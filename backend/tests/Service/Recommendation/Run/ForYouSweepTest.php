<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Feed;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\DueRecommendationRunFinder;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\ThrowingClock;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

final class ForYouSweepTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testSweepOnceStartsAndTicksDueProfileRunsToo(): void
    {
        $owner = $this->user('sweep-profile@example.test');
        $this->fixtures->seedReadyAiSettings($owner);
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(6, null, 40, 80));

        $report = $this->sweep()->sweepOnce();

        self::assertSame(1, $report->startedProfileRuns);
        self::assertSame(1, $report->advancedProfileRuns);
        self::assertSame(0, $report->activeProfileRuns);
        self::assertSame(0, $report->startedRuns);
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
        $repository = $this->entityManager->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
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
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        for ($index = 0; $index < 5; $index++) {
            $this->fixtures->entry($feed, $email . '-entry-' . $index, 60 - $index);
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
        $this->entityManager->clear();

        self::assertGreaterThanOrEqual(2, $started);
        self::assertNotNull($this->runs()->findActiveForUser($first));
        self::assertNotNull($this->runs()->findActiveForUser($second));
    }

    public function testSweepOnceStartsThenSnapshotsADueUsersRun(): void
    {
        $user = $this->seedDueUserWithCandidates('sweep-once@example.test');

        $report = $this->sweep()->sweepOnce();
        $this->entityManager->clear();

        self::assertGreaterThanOrEqual(1, $report->startedRuns);
        self::assertGreaterThanOrEqual(1, $report->advancedRuns);

        // One advance is the snapshot step (no provider call), so the run is
        // now RUNNING rather than PENDING or completed.
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Running, $run->getStatus());
    }

    /**
     * While it advances runs the cron sweep is the install's driver, observed during the provider call: a poll must
     * see it driving, and it must never read as a persistent worker, or Settings would say the cron entry can go.
     */
    public function testTheSweepClaimsDriverLivenessWhileItAdvancesARun(): void
    {
        $this->seedDueUserWithCandidates('sweep-presence@example.test');
        $this->sweep()->sweepOnce(); // starts the run and snapshots it; no provider call yet
        $this->entityManager->clear();

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

    /**
     * The pre-run mark cannot outlast a provider call longer than the freshness window, so the sweep arms the
     * mid-call heartbeat: the key is dropped inside the call, and the beat has to put it back.
     */
    public function testTheSweepArmsTheMidCallHeartbeat(): void
    {
        $this->seedDueUserWithCandidates('sweep-mid-call-beat@example.test');
        $this->sweep()->sweepOnce();
        $this->entityManager->clear();

        $this->chatClient()->queueContent('{"recommendations":[]}');
        $livenessAfterTheBeat = null;
        $this->chatClient()->duringNextCall(function () use (&$livenessAfterTheBeat): void {
            $this->presence()->forget(RecommendationDriverKind::CronSweep);
            $this->heartbeat()->beat();
            $livenessAfterTheBeat = $this->presence()->isAnybodyDrivingRecommendationRuns();
        });

        $this->sweep()->sweepOnce();

        self::assertTrue($livenessAfterTheBeat, 'A chunk arriving mid-call must refresh the sweep liveness.');
    }

    /**
     * A heartbeat left armed would write the surrendered key straight back. Nothing beats during this sweep, so the
     * beat below is the first one due and lands if the disarm is missing.
     */
    public function testTheSweepDisarmsTheMidCallHeartbeatOnItsWayOut(): void
    {
        $this->seedDueUserWithCandidates('sweep-heartbeat-disarm@example.test');
        $this->sweep()->sweepOnce();
        $this->entityManager->clear();
        $this->chatClient()->queueContent('{"recommendations":[]}');

        $this->sweep()->sweepOnce();
        $this->heartbeat()->beat();

        self::assertFalse(
            $this->presence()->isAnybodyDrivingRecommendationRuns(),
            'A beat after the sweep must not write back the key the sweep surrendered.',
        );
    }

    /**
     * A pass that dies partway must still surrender its key, or every browser defers to a finished sweep for the rest
     * of the freshness window. The presence clock gives one good reading, then fails the second run's mark.
     */
    public function testSurrendersItsKeyEvenWhenThePassDiesPartWayThrough(): void
    {
        $this->seedDueUserWithCandidates('sweep-finally-one@example.test');
        $this->seedDueUserWithCandidates('sweep-finally-two@example.test');

        try {
            $this->sweepMarkingWith(new ThrowingClock(1))->sweepOnce();
            self::fail('The throwing clock must have surfaced.');
        } catch (\RuntimeException $expected) {
            self::assertSame(ThrowingClock::MESSAGE, $expected->getMessage());
        }

        // The row itself, read fresh: the mark this pass did make carries the
        // throwing clock's own instant, which any freshness question would
        // call stale for reasons that have nothing to do with the cleanup.
        self::assertNull(
            $this->heartbeats()->findTouchedAt(RecommendationDriverKind::CronSweep->heartbeatName()),
        );
    }

    /**
     * When the gateway kills the cron's request a shutdown hook surrenders the key; on an ordinary pass it repeats the
     * `finally`'s forget unguarded, so forgetting an already-forgotten name must change and raise nothing.
     */
    public function testTheCleanupTheShutdownHookRepeatsIsSafeToRunTwice(): void
    {
        $this->seedDueUserWithCandidates('sweep-double-surrender@example.test');

        $this->sweep()->sweepOnce();
        // Byte for byte what the hook does after the `finally` has done it.
        $this->presence()->forget(RecommendationDriverKind::CronSweep);

        self::assertNull(
            $this->heartbeats()->findTouchedAt(RecommendationDriverKind::CronSweep->heartbeatName()),
        );
        self::assertFalse($this->presence()->isAnybodyDrivingRecommendationRuns());
    }

    /**
     * The container's sweep with its liveness bookkeeping swapped for one on
     * the given clock, so a test can decide when marking fails. Everything
     * else is the wiring the container built.
     */
    private function sweepMarkingWith(ClockInterface $clock): ForYouSweep
    {
        $presence = new WorkerPresence($this->heartbeats(), $clock);

        return new ForYouSweep(
            $this->service(DueRecommendationRunFinder::class),
            $this->service(RecommendationRunStarter::class),
            $this->service(RecommendationRunAdvancer::class),
            $this->service(ProfileRunSweep::class),
            $this->runs(),
            $presence,
            new SweepStreamHeartbeat($presence, $clock),
            $this->entityManager,
            new NullLogger(),
        );
    }

    /**
     * @template TService of object
     *
     * @param class-string<TService> $id
     *
     * @return TService
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * The report's advanced count is per run, not per sweep: the maintenance
     * endpoint's caller reads it to see whether a pass did any work at all.
     */
    public function testSweepOnceCountsEveryRunItAdvances(): void
    {
        $this->seedDueUserWithCandidates('sweep-count-one@example.test');
        $this->seedDueUserWithCandidates('sweep-count-two@example.test');

        $report = $this->sweep()->sweepOnce();

        self::assertSame(2, $report->advancedRuns);
    }

    private function heartbeat(): SweepStreamHeartbeat
    {
        $heartbeat = self::getContainer()->get(SweepStreamHeartbeat::class);
        self::assertInstanceOf(SweepStreamHeartbeat::class, $heartbeat);

        return $heartbeat;
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
