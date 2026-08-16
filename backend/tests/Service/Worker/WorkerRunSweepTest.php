<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Entity\WorkerHeartbeat;
use App\Repository\RecommendationRunRepository;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Service\Worker\SweepStreamHeartbeat;
use Symfony\Component\Clock\MockClock;
use App\Tests\DbTestCase;
use App\Tests\Support\ClearTrackingEntityManager;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ThrowingClock;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The shared worker-regime sweep (#371). Its coordination behavior --
 * heartbeat per run, error ladder, identity-map hygiene -- is pinned by
 * AdvanceRecommendationRunsHandlerTest, which now exercises it through the
 * handler's delegation. What is new here is the return value: the drain
 * command loops until sweep() reports no active run was attempted, so the
 * count is load-bearing, not informational.
 */
final class WorkerRunSweepTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testSweepWithNoActiveRunsReturnsZeroAndStillReportsLiveness(): void
    {
        self::assertSame(0, $this->sweep()->sweep(RecommendationDriverKind::PersistentWorker));
        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    /**
     * The sweep marks whoever runs it and nothing else (#371 follow-up). Run
     * by the drainer, it must claim the drainer's key alone: the settings card
     * reads the persistent worker's key to decide whether the install still
     * needs a cron, and a drainer never starts a due run.
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
            self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        }
    }

    /**
     * The identity map is per-sweep state, so its cleanup is a `finally` and
     * not a trailing statement -- a sweep that dies mid-run must not leave a
     * dirty map for the next one, and the drain command runs sweep after
     * sweep in one process (#371 final review, Finding 9). The presence clock
     * is the seam: the sweep marks the heartbeat once per run it advances, so
     * a clock good for exactly one reading carries the first of these two runs
     * and then fails inside the loop, after findAllActive() has already filled
     * the map.
     */
    public function testClearsTheIdentityMapEvenWhenTheSweepBodyThrows(): void
    {
        $first = $this->user('sweep-clear-on-throw-first@example.test');
        $this->fixtures->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('sweep-clear-on-throw-second@example.test');
        $this->fixtures->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        $clearTracker = new ClearTrackingEntityManager($this->em);
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

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

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

    /**
     * Built by hand, not fetched from the container: until the drain command
     * exists (a later task), the handler is this service's only reference,
     * and the compiler inlines single-reference private services away -- the
     * test container then cannot fetch it (the same caveat
     * config/services_test.yaml documents for StubChatClient's neighbours).
     */
    private function sweep(): WorkerRunSweep
    {
        return new WorkerRunSweep(
            $this->runs(),
            $this->advancer(),
            $this->presence(),
            $this->streamHeartbeat($this->presence()),
            $this->em,
            new NullLogger(),
        );
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    /**
     * Through the EntityManager rather than the container: the repository has
     * a single referrer (WorkerPresence), and the compiler inlines
     * single-reference private services away.
     */
    private function heartbeats(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }
    /**
     * A heartbeat over the same presence the sweep marks with. It only ever
     * writes while a completion is streaming, and nothing in these tests
     * streams — StubChatClient answers in one piece — so it is inert here and
     * does not disturb the mark counts the presence clocks pin.
     */
    private function streamHeartbeat(WorkerPresence $presence): SweepStreamHeartbeat
    {
        return new SweepStreamHeartbeat($presence, new MockClock());
    }
}
