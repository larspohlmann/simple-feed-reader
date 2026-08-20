<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RecommendationDrainCommand;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Worker\SweepStreamHeartbeat;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\LockKeyExpiringBeforeEveryRefreshStore;
use App\Tests\Support\LockLostAfterFirstRefreshStore;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\ThrowingClock;
use App\Tests\Support\TickingClock;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationDrainCommandTest extends DbTestCase
{
    /**
     * candidatePoolSize 20 with the batchCount expert override forced to 2
     * makes packBatches produce exactly two batches of 10 (see
     * seedTwoBatchFixture) -- enough to prove the drain command's loop
     * actually loops, which a single-batch fixture cannot: one sweep
     * finalizes a single-batch run outright, so a command that replaced its
     * `while` with a one-shot `if` would still pass a single-batch test.
     */
    private const int TWO_BATCH_ENTRY_COUNT = 20;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    /**
     * The whole point of the drainer: it does not advance once and exit, it
     * loops until nothing is active. batchConcurrency defaults to 1, so a
     * snapshotted two-batch run needs three sweeps to complete -- one
     * provider tick per batch, then one dedup tick -- and a fourth sweep to
     * observe that nothing is left; a one-shot `if` instead of the `while`
     * would leave this run running forever, one sweep short. Completion
     * proves the loop really looped, not just that it fired once.
     */
    public function testDrainsAnActiveRunToCompletionAndReleasesTheLock(): void
    {
        $user = $this->user('drain-to-completion@example.test');
        $this->seedTwoBatchFixture($user);
        $run = $this->startAndSnapshot($user);
        $this->queueBatchReply($run->getCandidateBatches()[0]);
        $this->queueBatchReply($run->getCandidateBatches()[1]);
        $this->queueCleanDedupReply();

        $exitCode = $this->execute($this->command());

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted->getStatus());

        // The lock must be free again, or the next spawn could never drain.
        $lock = $this->lockFactory()->createLock(RecommendationDrainCommand::LOCK_NAME);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /**
     * Concurrent spawns are by design (start + cron racing); the loser must
     * neither wait nor advance anything -- the winner already owns the work.
     */
    public function testExitsImmediatelyWithoutAdvancingWhenTheLockIsHeld(): void
    {
        $user = $this->user('drain-lock-contention@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $heldByAnotherDrainer = $this->lockFactory()->createLock(RecommendationDrainCommand::LOCK_NAME);
        self::assertTrue($heldByAnotherDrainer->acquire());

        try {
            $exitCode = $this->execute($this->command());
        } finally {
            $heldByAnotherDrainer->release();
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame(RecommendationRun::STATUS_PENDING, $run->getStatus());
    }

    /**
     * A stuck run must never pin a process forever: the cap ends the loop
     * with the run still active, and the cron tick respawns later. The
     * TickingClock steps a full hour per reading, so the very first cap
     * check is already past MAX_RUNTIME_SECONDS.
     */
    public function testStopsAtTheWallClockCapWithTheRunStillActive(): void
    {
        $user = $this->user('drain-wall-cap@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $hourPerReading = new TickingClock(
            new \DateTimeImmutable('2026-08-14 00:00:00'),
            RecommendationDrainCommand::MAX_RUNTIME_SECONDS,
        );
        $exitCode = $this->execute($this->command($hourPerReading));

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        self::assertNotNull($this->runs()->findActiveForUser($user));
    }

    /**
     * A lock genuinely taken over by a second drainer must degrade to the
     * same clean handoff as never winning acquire() in the first place: the
     * exception refresh() throws must never escape the command, and the bid
     * to take the key back must lose, leaving the work to its new owner. The
     * two-batch fixture is what makes the handoff observable -- the run is
     * still active when this drainer walks away, one sweep short of done.
     */
    public function testHandsOverCleanlyWhenAnotherDrainerHoldsTheLock(): void
    {
        $user = $this->user('drain-lost-lock@example.test');
        $this->seedTwoBatchFixture($user);
        $run = $this->startAndSnapshot($user);
        $this->queueBatchReply($run->getCandidateBatches()[0]);

        $command = $this->commandWithLockStore(
            new LockLostAfterFirstRefreshStore(new DoctrineDbalStore($this->em->getConnection())),
        );

        $exitCode = $this->execute($command);

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        self::assertNotNull($this->runs()->findActiveForUser($user));
    }

    /**
     * A refresh that fails only proves the key is gone, not that anyone else
     * took it -- and abandoning a healthy drain on that reading drops the run
     * back to the once-a-minute cron. So the drainer bids for the key again
     * and keeps going, which is what carrying this two-batch run all the way
     * to COMPLETED proves: every refresh in this store lapses the key.
     */
    public function testKeepsDrainingWhenTheLockKeyMerelyExpired(): void
    {
        $user = $this->user('drain-lock-expired@example.test');
        $this->seedTwoBatchFixture($user);
        $run = $this->startAndSnapshot($user);
        $this->queueBatchReply($run->getCandidateBatches()[0]);
        $this->queueBatchReply($run->getCandidateBatches()[1]);
        $this->queueCleanDedupReply();

        $command = $this->commandWithLockStore(
            new LockKeyExpiringBeforeEveryRefreshStore(new DoctrineDbalStore($this->em->getConnection())),
        );

        $exitCode = $this->execute($command);

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted->getStatus());
    }

    /**
     * The drainer is a worker only while it lives. Leaving the heartbeat
     * fresh on the way out makes the poll driver report the run as running in
     * the background, and stops the cron's respawn net from spawning a
     * replacement, for up to WorkerPresence::FRESH_SECONDS -- eleven minutes
     * of a frozen run on a worker-less install (#371 final review, Finding 1).
     * The wall cap is the cheapest way to leave the command with a run still
     * active, which is exactly the state that would freeze.
     */
    public function testSurrendersTheWorkerHeartbeatWhenItExitsWithRunsStillActive(): void
    {
        $user = $this->user('drain-heartbeat-surrender@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $hourPerReading = new TickingClock(
            new \DateTimeImmutable('2026-08-14 00:00:00'),
            RecommendationDrainCommand::MAX_RUNTIME_SECONDS,
        );
        $this->execute($this->command($hourPerReading));

        $this->em->clear();
        self::assertNotNull($this->runs()->findActiveForUser($user));
        self::assertFalse($this->presence()->isAnybodyDrivingRecommendationRuns());
    }

    /**
     * The lock TTL is pinned as a RELATIONSHIP, because that is what the
     * choice actually is (#371 follow-up). Above one call on the standard
     * profile, so the usual sweep -- one run, one call -- never lapses at all.
     * Below one worst-case sweep, deliberately: the TTL is what a SIGKILLed
     * drainer costs a replacement, and sizing it for the worst sweep bought a
     * five-hour respawn blackout to prevent a lapse that
     * testKeepsDrainingWhenTheLockKeyMerelyExpired proves is survivable.
     *
     * The standard profile is the right yardstick here even though a slow
     * connection can outlast the TTL in one call (#433): that is the same
     * survivable lapse, and holding the drain lock for a multiple of an hour
     * to avoid it would make a SIGKILL cost hours of respawn blackout.
     */
    public function testTheLockOutlivesOneProviderCallButNotAWorstCaseSweep(): void
    {
        self::assertGreaterThan(
            ProviderTimeouts::standard()->wallClockSeconds,
            RecommendationDrainCommand::LOCK_TTL_SECONDS,
        );
        self::assertLessThan(
            RecommendationRun::MAX_ATTEMPTS * ProviderTimeouts::standard()->wallClockSeconds,
            RecommendationDrainCommand::LOCK_TTL_SECONDS,
        );
    }

    /**
     * The spawner launches the command with --detach so the child leaves the
     * spawning request's session; an option the command never declared would
     * make every launch die on an unrecognised argument.
     */
    public function testTheDetachOptionIsDeclared(): void
    {
        self::assertTrue($this->command()->getDefinition()->hasOption('detach'));
    }

    /**
     * The lock release is a `finally`, not a trailing statement, and a
     * drainer that dies mid-drain without releasing parks the key for
     * LOCK_TTL_SECONDS. A clock that throws on its first reading is the
     * cheapest way to make the drain body fail; what matters is that the key
     * is free afterwards.
     */
    public function testReleasesTheLockEvenWhenTheDrainBodyThrows(): void
    {
        $command = $this->command(new ThrowingClock());

        try {
            $this->execute($command);
            self::fail('The throwing clock must have surfaced.');
        } catch (\RuntimeException $expected) {
            self::assertSame(ThrowingClock::MESSAGE, $expected->getMessage());
        }

        $lock = $this->lockFactory()->createLock(RecommendationDrainCommand::LOCK_NAME);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    private function execute(RecommendationDrainCommand $command): int
    {
        return (new CommandTester($command))->execute([]);
    }

    private function command(?ClockInterface $clock = null): RecommendationDrainCommand
    {
        return $this->commandWith($this->lockFactory(), $clock);
    }

    private function commandWithLockStore(PersistingStoreInterface $store): RecommendationDrainCommand
    {
        return $this->commandWith(new LockFactory($store));
    }

    private function commandWith(LockFactory $lockFactory, ?ClockInterface $clock = null): RecommendationDrainCommand
    {
        return new RecommendationDrainCommand(
            $lockFactory,
            $this->sweep(),
            $clock ?? new TickingClock(new \DateTimeImmutable('2026-08-14 00:00:00'), 1),
            $this->presence(),
        );
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    /**
     * Built by hand for the same inlining reason as WorkerRunSweepTest: a
     * private service with too few references may be inlined away, and this
     * command is hand-built anyway so a test clock can replace real sleeping.
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

    private function lockFactory(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = self::getContainer()->get(LockFactory::class);

        return $factory;
    }

    private function startAndSnapshot(User $user): RecommendationRun
    {
        $this->starter()->start($user);
        $this->advancer()->advance($user);
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * @param list<int> $batchIds
     */
    private function queueBatchReply(array $batchIds): void
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);
        $client->queueContent(json_encode([
            'recommendations' => array_map(
                static fn (int $id, int $index): array => [
                    'id' => $id,
                    'score' => 100 - $index,
                    'reason' => 'irrelevant',
                ],
                $batchIds,
                array_keys($batchIds),
            ),
        ], \JSON_THROW_ON_ERROR));
    }

    private function queueCleanDedupReply(): void
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);
        $client->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
    }

    /**
     * The batchCount expert override forces packBatches to split the pool
     * into exactly two batches of 10 regardless of the context window (see
     * RecommendationPromptBuilder::batchCap) -- unlike seedSingleBatchFixture,
     * this fixture cannot complete in one provider tick.
     */
    private function seedTwoBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);
        $this->fixtures->seedFeedWithEntries($user, self::TWO_BATCH_ENTRY_COUNT);

        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: self::TWO_BATCH_ENTRY_COUNT,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: 2,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->flush();
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

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
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
