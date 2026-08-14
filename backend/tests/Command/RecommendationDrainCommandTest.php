<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RecommendationDrainCommand;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\LockLostAfterFirstRefreshStore;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TickingClock;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
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
        $this->seedSingleBatchFixture($user);
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
        $this->seedSingleBatchFixture($user);
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
     * A lost lock between sweeps must degrade to the same clean handoff as
     * never winning acquire() in the first place -- another drainer already
     * owns the work, so the exception refresh() throws must never escape
     * the command and turn a benign handoff into a hard failure.
     */
    public function testEndsCleanlyWhenTheLockIsLostBetweenSweeps(): void
    {
        $user = $this->user('drain-lost-lock@example.test');
        $this->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $command = new RecommendationDrainCommand(
            $this->lockFactoryThatLosesTheLockAfterFirstRefresh(),
            $this->sweep(),
            new TickingClock(new \DateTimeImmutable('2026-08-14 00:00:00'), 1),
        );

        $exitCode = $this->execute($command);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    private function execute(RecommendationDrainCommand $command): int
    {
        return (new CommandTester($command))->execute([]);
    }

    private function command(?TickingClock $clock = null): RecommendationDrainCommand
    {
        return new RecommendationDrainCommand(
            $this->lockFactory(),
            $this->sweep(),
            $clock ?? new TickingClock(new \DateTimeImmutable('2026-08-14 00:00:00'), 1),
        );
    }

    /**
     * Built by hand for the same inlining reason as WorkerRunSweepTest: a
     * private service with too few references may be inlined away, and this
     * command is hand-built anyway so a test clock can replace real sleeping.
     */
    private function sweep(): WorkerRunSweep
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return new WorkerRunSweep($this->runs(), $this->advancer(), $presence, $this->em, new NullLogger());
    }

    private function lockFactory(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = self::getContainer()->get(LockFactory::class);

        return $factory;
    }

    /**
     * A LockFactory backed by the same Doctrine table the real one uses, so
     * the initial acquire() genuinely succeeds, but every refresh after the
     * first fails as if a second drainer had already re-acquired the key.
     */
    private function lockFactoryThatLosesTheLockAfterFirstRefresh(): LockFactory
    {
        $store = new LockLostAfterFirstRefreshStore(new DoctrineDbalStore($this->em->getConnection()));

        return new LockFactory($store);
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

    private function seedSingleBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);
        $this->seedFeedWithEntries($user, 5);
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
        $this->seedFeedWithEntries($user, self::TWO_BATCH_ENTRY_COUNT);

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

    private function seedFeedWithEntries(User $user, int $entryCount): void
    {
        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < $entryCount; $i++) {
            $this->fixtures->entry($feed, $user->getEmail() . '-entry-' . $i, $entryCount - $i);
        }
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
}
