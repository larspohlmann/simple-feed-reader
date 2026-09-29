<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Feed;
use App\Entity\RecommendationHistoryCaps;
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
use App\Service\Recommendation\Run\DueRecommendationRunFinder;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Run\SweepStreamHeartbeat;
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
            historyCaps: RecommendationHistoryCaps::defaults(),
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
     * The pre-run mark alone cannot carry a run whose single provider call
     * outlives the freshness window, so the sweep arms the mid-call heartbeat
     * too (#433, #439). The beat stands in for a chunk arriving long after
     * that mark aged out: the key is dropped inside the call, and the beat has
     * to put it back.
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
     * And disarms it again: a sweep that has ended is no longer evidence of
     * anything, and it has just surrendered its key -- a heartbeat left armed
     * would write that key straight back. Nothing beats during this sweep, so
     * the beat below is the first one due and would land if the disarm were
     * missing.
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
     * The key is surrendered in a `finally`, not in a trailing statement: a
     * pass that dies partway through must not leave every browser deferring
     * to a sweep that is over for the rest of the freshness window. The
     * presence clock is the seam -- one good reading carries the first run's
     * mark, and the second run's mark then fails inside the loop, with the key
     * already written.
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
     * `finally` is only half the cover, because the ending this sweep is most
     * exposed to does not unwind the stack: the gateway kills the request the
     * cron made, and a shutdown hook is what surrenders the key then. On every
     * ordinary pass that hook therefore fires on top of a `finally` that has
     * already surrendered, and it carries no has-it-been-cleaned-up flag --
     * unlike RecommendationDrainCommand's, whose hook also releases a lock. The
     * assumption that buys is exactly what this pins: forgetting a name that is
     * already forgotten changes nothing and raises nothing. Break it in
     * WorkerHeartbeatRepository and the hook needs a guard.
     *
     * What no in-process test can reach is the hook FIRING -- that is PHP's own
     * contract for a request the gateway cuts off, and the reason the two
     * shutdown hooks already in this tree (RecommendationRunAdvancer,
     * RecommendationDrainCommand) are covered no further than this either.
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
