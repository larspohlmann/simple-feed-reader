<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\RecommendationRun;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Run\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ClearTrackingEntityManager;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\ThrowingClock;
use App\Tests\Support\TickingClock;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * AdvanceRecommendationRunsHandlerTest pins the sweep's coordination through the handler. Here: the returned count,
 * which the drain command loops on until no run was attempted.
 */
final class WorkerRunSweepTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testSweepWithNoActiveRunsReturnsZeroAndStillReportsLiveness(): void
    {
        self::assertSame(0, $this->sweep()->sweep(RecommendationDriverKind::PersistentWorker));
        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    /**
     * The sweep marks only its caller's key: the settings card reads the persistent worker's key to decide whether an
     * install still needs a cron, and a drainer never starts a due run.
     */
    public function testASweepRunByTheDrainerClaimsOnlyTheDrainerKey(): void
    {
        $this->sweep()->sweep(RecommendationDriverKind::OnDemandDrainer);

        self::assertTrue($this->presence()->isAnybodyDrivingRecommendationRuns());
        self::assertFalse($this->presence()->hasPersistentRecommendationWorker());
    }

    public function testSweepReturnsOneAttemptPerActiveRun(): void
    {
        $first = $this->user('sweep-count-first@example.test');
        $this->fixtures->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('sweep-count-second@example.test');
        $this->fixtures->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        // Both runs are PENDING; the sweep's snapshot tick advances each
        // without a provider call, so the count is observable without
        // stubbing replies.
        self::assertSame(2, $this->sweep()->sweep(RecommendationDriverKind::PersistentWorker));
        foreach ([$first, $second] as $user) {
            $run = $this->runs()->findActiveForUser($user);
            self::assertNotNull($run);
            self::assertSame(RunStatus::Running, $run->getStatus());
        }
    }

    /**
     * clear() sits in `finally`: the drain command runs sweep after sweep in one process. The seam is a presence clock
     * good for one reading: it carries the first run, then fails inside the loop after findAllActive() filled the map.
     */
    public function testClearsTheIdentityMapEvenWhenTheSweepBodyThrows(): void
    {
        $first = $this->user('sweep-clear-on-throw-first@example.test');
        $this->fixtures->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('sweep-clear-on-throw-second@example.test');
        $this->fixtures->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        $clearTracker = new ClearTrackingEntityManager($this->entityManager);
        $presence = new WorkerPresence($this->heartbeats(), new ThrowingClock(1));
        $sweep = new WorkerRunSweep(
            $this->runs(),
            $this->advancer(),
            $presence,
            $this->streamHeartbeat($presence),
            $clearTracker,
            new NullLogger(),
        );

        try {
            $sweep->sweep(RecommendationDriverKind::PersistentWorker);
            self::fail('The throwing clock must have surfaced.');
        } catch (\RuntimeException $expected) {
            self::assertSame(ThrowingClock::MESSAGE, $expected->getMessage());
        }

        self::assertTrue($clearTracker->wasCleared());
    }

    /**
     * The sweep arms the mid-call heartbeat and disarms it after: a beat from inside the provider call reaches the
     * presence row, one after the sweep does not. The clock ticks, so the beat lands strictly after the pre-run mark.
     */
    public function testItArmsTheStreamHeartbeatForTheSweepAndDisarmsItAfterwards(): void
    {
        $user = $this->user('sweep-stream-heartbeat@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        // The first tick only snapshots the candidate pool -- no provider
        // call is made, so there is no stream to beat from. The second one
        // sends the batch.
        $this->sweep()->sweep(RecommendationDriverKind::PersistentWorker);
        // An empty but well-formed reply: this test is about the call
        // happening at all, not about what it ranks.
        $this->chatClient()->queueContent('{"recommendations":[]}');

        $clock = new TickingClock(new \DateTimeImmutable('2026-08-16 12:00:00'), 10);
        $presence = new WorkerPresence($this->heartbeats(), $clock);
        $heartbeat = new SweepStreamHeartbeat($presence, $clock);
        $this->chatClient()->duringNextCall(static function () use ($heartbeat): void {
            $heartbeat->beat();
        });

        $sweep = new WorkerRunSweep(
            $this->runs(),
            $this->advancer(),
            $presence,
            $heartbeat,
            $this->entityManager,
            new NullLogger(),
        );
        $sweep->sweep(RecommendationDriverKind::PersistentWorker);

        $touchedDuringTheCall = $this->touchedAt();
        self::assertNotNull($touchedDuringTheCall);
        self::assertGreaterThan(
            new \DateTimeImmutable('2026-08-16 12:00:00'),
            $touchedDuringTheCall,
            'A beat inside the provider call must have marked liveness after the pre-run mark.',
        );

        $heartbeat->beat();

        self::assertEquals(
            $touchedDuringTheCall,
            $this->touchedAt(),
            'Once the sweep has ended, a beat must write nothing.',
        );
    }

    private function touchedAt(): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }

    private function chatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->entityManager->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    private function sweep(): WorkerRunSweep
    {
        return new WorkerRunSweep(
            $this->runs(),
            $this->advancer(),
            $this->presence(),
            $this->streamHeartbeat($this->presence()),
            $this->entityManager,
            new NullLogger(),
        );
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    /** Writes only while a completion streams, and StubChatClient never streams: it cannot disturb the mark counts. */
    private function streamHeartbeat(WorkerPresence $presence): SweepStreamHeartbeat
    {
        return new SweepStreamHeartbeat($presence, new MockClock());
    }
}
