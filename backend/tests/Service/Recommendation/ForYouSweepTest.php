<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ForYouSweepTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function sweep(): ForYouSweep
    {
        $sweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $sweep);

        return $sweep;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $hours,
        ));
    }

    /** Ready AI + a feed with unread entries, so a snapshot has candidates and the run stays RUNNING. */
    private function seedDueUserWithCandidates(string $email): User
    {
        $user = $this->user($email);
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);

        $feed = new Feed('https://example.com/' . $email . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $this->fixtures->entry($feed, $email . '-entry-' . $i, 60 - $i);
        }

        return $user;
    }

    public function testStartDueRunsStartsARunForEachDueUser(): void
    {
        $first = $this->user('sweep-start-a@example.test');
        $this->fixtures->seedReadyAiSettings($first);
        $this->setCadence($first, 1);
        $second = $this->user('sweep-start-b@example.test');
        $this->fixtures->seedReadyAiSettings($second);
        $this->setCadence($second, 1);

        $started = $this->sweep()->startDueRuns();
        $this->em->clear();

        self::assertGreaterThanOrEqual(2, $started);
        self::assertNotNull($this->runs()->findActiveForUser($first));
        self::assertNotNull($this->runs()->findActiveForUser($second));
    }

    public function testSweepOnceStartsThenSnapshotsADueUsersRun(): void
    {
        $user = $this->seedDueUserWithCandidates('sweep-once@example.test');

        $report = $this->sweep()->sweepOnce();
        $this->em->clear();

        self::assertGreaterThanOrEqual(1, $report->startedRuns);
        self::assertGreaterThanOrEqual(1, $report->advancedRuns);

        // One advance is the snapshot step (no provider call), so the run is
        // now RUNNING rather than PENDING or completed.
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
    }

    /**
     * While the cron sweep advances runs it IS the install's driver, and the
     * browser polling the very account it is working on must be able to read
     * that (#439). The sweep used to mark no liveness at all, so that poll
     * found a held lock with nothing vouching for it and told the user their
     * healthy run had stalled.
     *
     * The provider call is the observation window: it is the one point that
     * is unambiguously inside a sweep. The same window answers the second
     * question WorkerPresence is asked -- a cron sweep must never read as a
     * persistent worker, or the settings card would tell the operator to drop
     * the very cron entry that is driving this run.
     */
    public function testTheSweepClaimsDriverLivenessWhileItAdvancesARun(): void
    {
        $this->seedDueUserWithCandidates('sweep-presence@example.test');
        $this->sweep()->sweepOnce(); // starts the run and snapshots it; no provider call yet
        $this->em->clear();

        // Well-formed and empty: this test is about who is driving, not about
        // what the model ranks.
        $this->chatClient()->queueContent('{"recommendations":[]}');
        $duringTheCall = [];
        $this->chatClient()->duringNextCall(function () use (&$duringTheCall): void {
            $duringTheCall = [
                'driving' => $this->presence()->isAnybodyDrivingRecommendationRuns(),
                'persistentWorker' => $this->presence()->hasPersistentRecommendationWorker(),
            ];
        });

        $this->sweep()->sweepOnce();

        self::assertSame(['driving' => true, 'persistentWorker' => false], $duringTheCall);
        self::assertFalse(
            $this->presence()->isAnybodyDrivingRecommendationRuns(),
            'The sweep must surrender its key on the way out, or the next poll tick would stop driving.',
        );
    }

    private function chatClient(): StubChatClient
    {
        $client = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $client);

        return $client;
    }

    private function presence(): WorkerPresence
    {
        $presence = self::getContainer()->get(WorkerPresence::class);
        self::assertInstanceOf(WorkerPresence::class, $presence);

        return $presence;
    }
}
