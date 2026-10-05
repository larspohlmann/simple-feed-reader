<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Exception\UnpersistedEntityException;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RecommendationBatchSize;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRunawayException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderTimeoutsModel;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Run\Model\CallProgressModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Run\TickLockTtl;
use App\Tests\DbTestCase;
use App\Tests\Support\AiSettingsRowMover;
use App\Tests\Support\BeatDuringReleaseLockFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TtlRecordingLockFactory;
use App\Tests\Support\UserFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final class RecommendationRunAdvancerTest extends DbTestCase
{
    private const int MULTI_BATCH_ENTRY_COUNT = 20;
    private const int SINGLE_BATCH_ENTRY_COUNT = 5;
    private const int MULTI_BATCH_CONTEXT_WINDOW = 2500;

    /** What Strato kills a web request at. */
    private const float STRATO_REQUEST_CAP_SECONDS = 240.0;

    private User $user;
    private Feed $feed;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('run-advancer@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
    }

    public function testTickWithoutAnyRunReportsNone(): void
    {
        $report = $this->advancer()->advance($this->user);

        self::assertSame('none', $report->status);
        self::assertNull($report->batchesTotal);
        self::assertSame(0, $report->batchesDone);
        self::assertNull($report->error);
    }

    public function testSnapshotTickPartitionsCandidatesAndReportsRunning(): void
    {
        $this->seedReadyAiSettings($this->user);
        for ($index = 0; $index < 5; $index++) {
            $this->entry('entry-' . $index, 60 - $index);
        }
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('running', $report->status);
        self::assertSame(2, $report->batchesTotal); // 1 batch + consolidate
        self::assertSame(0, $report->batchesDone);
        self::assertSame([], $this->stubChatClient()->calls());

        // Proves the batch plan was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame(RunStatus::Running, $persisted->getStatus());
        self::assertCount(5, $persisted->getCandidateBatches()[0] ?? []);
    }

    public function testSnapshotWithZeroCandidatesCompletesEmpty(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        // No candidates means no batch plan was ever frozen, so there is no
        // consolidate phase to count either.
        self::assertNull($report->batchesTotal);

        // Proves complete() was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
        self::assertSame(RunStatus::Completed, $persisted?->getStatus());
    }

    public function testSnapshotExcludesCandidatesOlderThanTheLookbackWindow(): void
    {
        $this->seedReadyAiSettings($this->user);
        // Default window is 2 days: 30 minutes ago is inside, 5 days ago is not.
        $inside = $this->entry('inside-window', 30);
        $this->entry('outside-window', 60 * 24 * 5);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $this->advancer()->advance($this->user);

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame([[$inside->getId()]], $persisted->getCandidateBatches());
    }

    public function testSnapshotWithEveryCandidateOutsideTheWindowCompletesEmpty(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->entry('long-gone', 60 * 24 * 30);
        $this->starter()->start($this->user);

        $report = $this->advancer()->advance($this->user);

        // Not a failure: an empty window freezes an empty plan, exactly like
        // an account with no unread entries at all.
        self::assertSame('completed', $report->status);
        self::assertNull($report->batchesTotal);
    }

    /**
     * A 3-day-old entry is outside DEFAULT_LOOKBACK_DAYS (2) but inside this reader's 5-day window, and a 10-day-old
     * one is outside both: a snapshot that ignored lookbackDays, or hardcoded any window, fails.
     */
    public function testSnapshotUsesTheUsersConfiguredLookbackWindow(): void
    {
        $this->seedReadyAiSettings($this->user);
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: new RecommendationPoolLimits(
                RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                5,
                RecommendationSettings::DEFAULT_PICKS_LIMIT,
            ),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        $insideWiderWindow = $this->entry('inside-wider-window', 60 * 24 * 3);
        $this->entry('outside-both-windows', 60 * 24 * 10);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $this->advancer()->advance($this->user);

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame([[$insideWiderWindow->getId()]], $persisted->getCandidateBatches());
    }

    public function testBusyWhenTheLockIsHeld(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

        $logSpy = $this->replaceLoggerWithASpy();
        $lock = $this->lockFactory()->createLock('ai-recommendations-' . $userId);
        self::assertTrue($lock->acquire());

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('busy', $report->status);
            self::assertNull($report->batchesTotal);
            self::assertSame(0, $report->batchesDone);
            self::assertNull($report->error);
        } finally {
            $lock->release();
        }

        // The advancer cannot tell a held lock from a stall, since it never reads driver liveness: the log line is
        // RecommendationPollDriver's, which knows both halves.
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * A poll tab repeats the failed acquire every few seconds while somebody else drives the run: it stays silent,
     * and it stays `busy`.
     */
    public function testARepeatedLockContentionStaysSilentToo(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

        $logSpy = $this->replaceLoggerWithASpy();
        $lock = $this->lockFactory()->createLock('ai-recommendations-' . $userId);
        self::assertTrue($lock->acquire());

        try {
            $this->advancer()->advance($this->user);
            $second = $this->advancer()->advance($this->user);
        } finally {
            $lock->release();
        }

        self::assertSame('busy', $second->status);
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * A run whose retry_not_before is in the future spends no provider call and reports running. Written straight to
     * the database: the ticking side's entity predates the write, as it would in another process.
     */
    public function testATickWithinItsRetryWindowMakesNoProviderCall(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot(); // run is RUNNING, ready for the batch phase
        $runId = $run->requireId();

        $this->entityManager->getConnection()->update(
            'recommendation_run',
            ['retry_not_before' => '2099-01-01 00:00:00'],
            ['id' => $runId],
        );
        $this->entityManager->clear();

        $callsBefore = \count($this->stubChatClient()->calls());
        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertCount($callsBefore, $this->stubChatClient()->calls()); // no new provider call
    }

    private function replaceLoggerWithASpy(): TestHandler
    {
        $logSpy = new TestHandler();
        // The default logger, which the advancer autowires, has to be swapped before anything first resolves it.
        self::getContainer()->set('monolog.logger', new Logger('test', [$logSpy]));

        return $logSpy;
    }

    /**
     * fail() accepts a pending run, so one whose configuration disappears before its first snapshot ends failed on a
     * poll tick too. The exception still reaches the caller, so the controller's HTTP mapping is unchanged.
     */
    public function testAPollTickFailsAPendingRunWhenTheConfigurationDisappears(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->entry('entry-configless', 30);
        $this->starter()->start($this->user);
        $runId = $this->activeRun()->getId();
        self::assertNotNull($runId);

        $this->deleteAiSettings();

        try {
            $this->advancer()->advance($this->user);
            self::fail('advance() must surface the missing configuration.');
        } catch (AiNotConfiguredException) {
            // Expected: the caller still sees the error on this tick.
        }

        $this->entityManager->clear();
        $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame(RunStatus::Failed, $persisted->getStatus());
        self::assertSame('The AI provider is no longer configured.', $persisted->getError());
    }

    private function deleteAiSettings(): void
    {
        $this->fixtures->deleteAiSettings($this->user);
    }

    public function testAdvancingForAnUnsavedUserIsRefused(): void
    {
        $unsavedUser = new User('unsaved@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        $this->expectException(UnpersistedEntityException::class);
        $this->expectExceptionMessage(User::class);

        $this->advancer()->advance($unsavedUser);
    }

    /**
     * Proves the per-user lock is released once a tick finishes: a second,
     * independent lock on the exact same resource name must be acquirable
     * right after advance() returns.
     */
    public function testAdvanceReleasesTheLockAfterATick(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

        $this->advancer()->advance($this->user);

        $lock = $this->lockFactory()->createLock('ai-recommendations-' . $userId);

        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /**
     * Exact, not bounded: bounds would also pass a TTL sized for a whole call. The Strato cap is asserted apart,
     * because a smaller margin keeps the sum true and drops the TTL under it.
     */
    #[DataProvider('timeoutProfiles')]
    public function testLockTtlClearsTheLongestSilenceALiveHolderCanProduce(
        bool $slowModel,
        float $firstByteSeconds,
    ): void {
        $this->fixtures->seedReadyAiSettings($this->user);
        $this->markConnectionSlow($slowModel);
        $lockFactory = $this->recordLockTtls();

        $this->advancer()->advance($this->user);

        $profile = $slowModel ? ProviderTimeoutsModel::forSlowModel() : ProviderTimeoutsModel::standard();
        self::assertSame(
            $firstByteSeconds,
            $profile->firstByteSeconds,
            'The profile under test must be the one the connection resolves to.',
        );

        $ttl = $lockFactory->lastTtlFor('ai-recommendations-' . $this->user->getId());
        self::assertSame(
            $firstByteSeconds + TickLockTtl::MARGIN_SECONDS,
            $ttl,
            'The TTL is one first-byte silence plus the margin, and nothing else.',
        );
        self::assertGreaterThanOrEqual(
            self::STRATO_REQUEST_CAP_SECONDS,
            $ttl,
            'A TTL under the cap Strato kills a web request at lets the next tick in '
                . 'while the killed one may still be running.',
        );
    }

    /**
     * @return iterable<string, array{bool, float}>
     */
    public static function timeoutProfiles(): iterable
    {
        yield 'standard' => [false, 180.0];
        yield 'slow model' => [true, 900.0];
    }

    /**
     * A streamed chunk must refresh the lock, or it expires under a working tick. The lifetimes are read inside the
     * provider call: the only moment the lock is both held and observable.
     */
    public function testATickThatStreamsRefreshesItsLock(): void
    {
        $lockFactory = $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];

        /** @var list<?float> $lifetimes */
        $lifetimes = [];
        $this->stubChatClient()->duringNextCall(function () use ($lockFactory, &$lifetimes): void {
            $lock = $this->tickLock($lockFactory);
            // Each lifetime read is a database round-trip: let the fresh lock age past its jitter first, or the
            // refresh's bump is lost in it and the comparison below is a coin-flip.
            usleep(250_000);
            $lifetimes[] = $lock->getRemainingLifetime();
            $this->providerCallHeartbeat()->beat();
            $lifetimes[] = $lock->getRemainingLifetime();
        });
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $this->advancer()->advance($this->user);

        self::assertGreaterThan(
            $lifetimes[0],
            $lifetimes[1],
            'A chunk arriving mid-call must push the tick lock expiry back.',
        );
    }

    /**
     * After the tick a beat must not touch the released lock: a keepalive left armed would refresh a lock this
     * process no longer owns, or a stranger's.
     */
    public function testTheKeepaliveIsReleasedAfterATick(): void
    {
        $lockFactory = $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $lock = $this->tickLock($lockFactory);
        $lifetimeAfterTheTick = $lock->getRemainingLifetime();
        $this->providerCallHeartbeat()->beat();

        self::assertLessThanOrEqual(
            $lifetimeAfterTheTick,
            $lock->getRemainingLifetime(),
            'A beat after the tick refreshed the lock the tick had already released.',
        );
    }

    /**
     * advance() disarms the keepalive before it releases the lock. BeatDuringReleaseLock beats from inside the
     * release: disarmed first, the beat finds nothing held; reversed, the lifetime jumps back to a full TTL.
     */
    public function testABeatArrivingAsTheLockIsReleasedRefreshesNothing(): void
    {
        $lockFactory = new BeatDuringReleaseLockFactory(
            new DoctrineDbalStore($this->entityManager->getConnection()),
            $this->providerCallHeartbeat(),
        );
        self::getContainer()->set(LockFactory::class, $lockFactory);
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $lock = $lockFactory->lastLockFor('ai-recommendations-' . $this->user->getId());
        self::assertNotNull($lock, 'The tick must have created its per-user lock.');
        self::assertNotNull(
            $lock->lifetimeBeforeTheBeat(),
            'The lock must have been released while it still had a lifetime to observe.',
        );

        self::assertLessThanOrEqual(
            $lock->lifetimeBeforeTheBeat(),
            $lock->lifetimeAfterTheBeat(),
            'A beat landing as the lock is released must not refresh it: the keepalive is disarmed first.',
        );
    }

    /**
     * A refresh rejected because another process holds the name means the double-bank has begun: the tick stops at
     * its next RecommendationTickCheckpoint, before banking the usable reply the theft interrupted.
     */
    public function testATickThatLostItsLockStopsBeforeBankingItsWinners(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $runId = $run->requireId();

        $thief = null;
        $this->stubChatClient()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('running', $report->status);

            $this->entityManager->clear();
            $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
            self::assertNotNull($persisted);
            self::assertSame(0, $persisted->getProgress()->batchesDone);
            self::assertSame([], $persisted->getWinners());
        } finally {
            $thief?->release();
        }
    }

    /**
     * Losing the lock and a transport failure can meet in one round, and the failure path must not write to the run
     * either. The counter is read from the row: the entity is the unwritten copy under test.
     */
    public function testATickThatLostItsLockRecordsNoTransportFailureAgainstTheRun(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $runId = $run->requireId();

        $thief = null;
        $this->stubChatClient()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('running', $report->status);

            $this->entityManager->clear();
            $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
            self::assertNotNull($persisted);
            self::assertSame(0, $this->persistedTransportFailures($persisted));
            self::assertSame(RunStatus::Running, $persisted->getStatus());
        } finally {
            $thief?->release();
        }
    }

    public function testATickThatLostItsLockDoesNotFailTheRunOnARejection(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $runId = $run->requireId();

        $thief = null;
        $this->stubChatClient()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->stubChatClient()->queueFailure(
            new ProviderRejectedRequestException(400, 'That provider refused the request (status 400): No.'),
        );

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('running', $report->status);

            $this->entityManager->clear();
            $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
            self::assertNotNull($persisted);
            self::assertSame(RunStatus::Running, $persisted->getStatus());
            self::assertNull($persisted->getError());
            self::assertFalse($this->persistedConnection()->refusesSuppressedReasoning());
        } finally {
            $thief?->release();
        }
    }

    /**
     * The consolidation phase's own checkpoint, after it settles its reply and before the advancer finalizes: a
     * separate statement from the batch wave's, held in place only by this test.
     */
    public function testATickThatLostItsLockDuringTheConsolidateCallDoesNotFinalize(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];
        $runId = $run->requireId();

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 80, 'reason' => 'from batch one']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'from batch two']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $thief = null;
        $this->stubChatClient()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->queueConsolidationReply(
            [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'from batch two']],
            [$firstBatch[0]],
        );

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('running', $report->status);

            $this->entityManager->clear();
            $persisted = $this->entityManager->getRepository(RecommendationRun::class)->find($runId);
            self::assertNotNull($persisted);
            self::assertSame(RunStatus::Running, $persisted->getStatus());
            self::assertSame([], $this->recommendationItems($persisted));
        } finally {
            $thief?->release();
        }
    }

    /**
     * Takes the running tick's lock name for a second process. The store keeps one row per name, so the row is
     * cleared first, as an expiry would from the tick's side.
     */
    private function stealTheTickLock(): SharedLockInterface
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM lock_keys');

        $thief = (new LockFactory(new DoctrineDbalStore($this->entityManager->getConnection())))
            ->createLock('ai-recommendations-' . $this->user->getId(), 60.0);
        self::assertTrue($thief->acquire(), 'The second process must be able to take the freed name.');

        return $thief;
    }

    /**
     * The lock the last tick created for this account, as the advancer's own
     * factory handed it out.
     */
    private function tickLock(TtlRecordingLockFactory $lockFactory): SharedLockInterface
    {
        $lock = $lockFactory->lastLockFor('ai-recommendations-' . $this->user->getId());
        self::assertNotNull($lock, 'The tick must have created its per-user lock.');

        return $lock;
    }

    private function providerCallHeartbeat(): ProviderCallHeartbeatInterface
    {
        /** @var ProviderCallHeartbeatInterface $heartbeat */
        $heartbeat = self::getContainer()->get(ProviderCallHeartbeatInterface::class);

        return $heartbeat;
    }

    /**
     * Swaps in a recording lock factory before the advancer is built, so the
     * advancer the container wires receives it.
     */
    private function recordLockTtls(): TtlRecordingLockFactory
    {
        $factory = new TtlRecordingLockFactory(new InMemoryStore());
        self::getContainer()->set(LockFactory::class, $factory);

        return $factory;
    }

    /**
     * The recording factory over the real store, for tests that watch a lock's remaining lifetime: InMemoryStore's
     * putOffExpiration is a no-op, so a refresh through it leaves nothing to observe.
     */
    private function recordLocksOverTheRealStore(): TtlRecordingLockFactory
    {
        $factory = new TtlRecordingLockFactory(new DoctrineDbalStore($this->entityManager->getConnection()));
        self::getContainer()->set(LockFactory::class, $factory);

        return $factory;
    }

    private function markConnectionSlow(bool $slowModel): void
    {
        $settings = $this->user->getActiveAiProviderSettings();
        self::assertNotNull($settings);
        $settings->setSlowModel($slowModel);
        $this->entityManager->flush();
    }

    public function testBatchTickRecordsWinnersAndAdvances(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1'],
                ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame('running', $report->status);
        self::assertSame(1, $report->batchesDone);

        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        $batchCall = $calls[0];
        self::assertSame('m', $batchCall['model']);
        self::assertStringContainsString(
            'You score candidate posts for one reader of an RSS reader.',
            $batchCall['messages'][0]['content'],
        );
        self::assertStringContainsString('- [' . $firstBatch[0], $batchCall['messages'][1]['content']);

        // The cap sent is the output bound for exactly this batch's candidates, derived from the batch, including
        // the reduced reasoning headroom a suppressed connection keeps.
        self::assertTrue($batchCall['suppressReasoning']);
        self::assertSame(
            (new RecommendationAnswerBudget())->outputBoundTokens(
                \count($firstBatch),
                RecommendationResponseSchema::BatchScore,
                reasoning: Reasoning::Suppressed,
            ),
            $batchCall['maxAnswerTokens'],
        );

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(
            [
                ['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1'],
                ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
            ],
            $persisted->getWinners()[0],
        );
    }

    /**
     * The first batch wave is one call, so it writes the provider's prompt-prefix cache before the other batches race
     * for it. The next tick fans out at full width, so the cap belongs to the first wave alone.
     */
    public function testFirstBatchWaveWarmsTheCacheWithOneCallThenFansOut(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $warmUp = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(1, $warmUp->batchesDone);
        // Exactly one batch call -- not three.
        self::assertCount(1, $this->stubChatClient()->calls());
        self::assertFalse($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 80, 'reason' => 'b']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 70, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));
        $fanOut = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(3, $fanOut->batchesDone);
        // The warm-up call, then both fanned-out batch calls.
        self::assertCount(3, $this->stubChatClient()->calls());
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);
    }

    /**
     * With concurrency 3 and three batches, the warm-up tick banks batch 0 alone, then one worker tick fans out
     * batches 1 and 2, advancing batchesDone by the wave size and keeping plan order.
     */
    public function testFanOutWaveBanksEveryRemainingBatchInOneTick(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 91, 'reason' => 'zero']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // The fan-out wave banks batches 1 and 2 together.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 92, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 93, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertSame(3, $report->batchesDone);
        // The warm-up call, then both fanned-out batch calls.
        self::assertCount(3, $this->stubChatClient()->calls());

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertTrue($persisted->getProgress()->isConsolidationPhase);
        self::assertSame(
            [['id' => $batches[0][0], 'score' => 91, 'reason' => 'zero']],
            $persisted->getWinners()[0],
        );
        self::assertSame(
            [['id' => $batches[1][0], 'score' => 92, 'reason' => 'one']],
            $persisted->getWinners()[1],
        );
        self::assertSame(
            [['id' => $batches[2][0], 'score' => 93, 'reason' => 'two']],
            $persisted->getWinners()[2],
        );
    }

    /**
     * An unusable batch in a fan-out wave retries in-tick and degrades after MAX_ATTEMPTS without dropping its usable
     * siblings. The FIFO stub serves round 1 [1 usable, 2 garbage], then [2 garbage] twice, all in one tick.
     */
    public function testUnusableBatchRetriesInTickThenDegradesWithoutDroppingSiblings(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // The fan-out wave: batch 1 ranks once, batch 2 is garbage every round.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'kept']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertSame(3, $report->batchesDone);
        // Warm-up, then batch 1 once and batch 2 three times -- the retries
        // stayed in-tick.
        self::assertCount(5, $this->stubChatClient()->calls());

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(RunStatus::Running, $persisted->getStatus());
        self::assertSame(
            [['id' => $batches[1][0], 'score' => 90, 'reason' => 'kept']],
            $persisted->getWinners()[1],
        );
        // The stubborn batch degraded to an empty winner set, not fatal.
        self::assertSame([], $persisted->getWinners()[2]);
    }

    public function testARejectedBatchCallFailsTheRunAtOnceAndSettlesEveryCallOfTheWave(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(3);
        $this->fixtures->stopSuppressingReasoning($this->user);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(
            new ProviderRejectedRequestException(400, 'That provider refused the request (status 400): No.'),
        );
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(RunStatus::Failed->value, $report->status);
        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: '
            . 'That provider refused the request (status 400): No.',
            $failed->getError(),
        );
        self::assertSame(0, $failed->getTransportFailures());
        $waveRows = \array_slice($this->logRowsOfLatestRun(), 1);
        self::assertSame(
            [CallVerdict::TransportFailed, CallVerdict::TransportFailed, CallVerdict::TransportFailed],
            array_column($waveRows, 'verdict'),
        );
        self::assertNotContains(null, array_column($waveRows, 'finishedAt'));
    }

    public function testAWaveRejectedForSuppressedReasoningIsAskedAgainWithoutItAndTheRunCompletes(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(new ProviderRejectedRequestException(
            400,
            'That provider refused the request (status 400): Reasoning effort "none" is not supported.',
        ));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(RunStatus::Running->value, $report->status);
        $this->entityManager->clear();
        $absorbed = $this->activeRun();
        self::assertSame(0, $absorbed->getTransportFailures());
        self::assertSame(1, $absorbed->getProgress()->batchesDone);
        self::assertTrue($this->persistedConnection()->refusesSuppressedReasoning());
        $waveRows = \array_slice($this->logRowsOfLatestRun(), 1);
        self::assertSame(
            [CallVerdict::TransportFailed, CallVerdict::TransportFailed, CallVerdict::TransportFailed],
            array_column($waveRows, 'verdict'),
        );
        self::assertNotContains(null, array_column($waveRows, 'finishedAt'));

        $callsBeforeTheResend = \count($this->stubChatClient()->calls());
        foreach ([1, 2, 3] as $batchIndex) {
            $this->stubChatClient()->queueContent(json_encode([
                'recommendations' => [['id' => $batches[$batchIndex][0], 'score' => 90, 'reason' => 'resent']],
            ], \JSON_THROW_ON_ERROR));
        }
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $this->queueConsolidationReply([
            ['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm'],
            ['id' => $batches[1][0], 'score' => 80, 'reason' => 'resent'],
        ]);
        $final = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(RunStatus::Completed->value, $final->status);
        $calls = $this->stubChatClient()->calls();
        self::assertSame(
            [true, true, true, true],
            array_column(\array_slice($calls, 0, $callsBeforeTheResend), 'suppressReasoning'),
        );
        self::assertSame(
            [false, false, false, false],
            array_column(\array_slice($calls, $callsBeforeTheResend), 'suppressReasoning'),
        );
    }

    public function testAWaveRejectionBeatsAnEarlierUnreachableCallAndFailsTheRunAtOnce(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(3);
        $this->fixtures->stopSuppressingReasoning($this->user);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->stubChatClient()->queueFailure(
            new ProviderRejectedRequestException(400, 'That provider refused the request (status 400): No.'),
        );
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(RunStatus::Failed->value, $report->status);
        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());
        self::assertSame(0, $failed->getTransportFailures());
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: '
            . 'That provider refused the request (status 400): No.',
            $failed->getError(),
        );
    }

    public function testAWaveOfTransportFailuresReportsTheFirstByIndex(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('first down'));
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('second down'));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->advancer()->advance($this->user, TickDriver::Worker);
            self::fail('The wave transport failure must propagate.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('first down', $exception->getMessage());
        }
    }

    public function testTransportFailureInWaveAdvancesNothingAndIncrementsCeilingOnce(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // The fan-out wave over batches 1..3: one call fails mid-round.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->advancer()->advance($this->user, TickDriver::Worker);
            self::fail('The wave transport failure must propagate.');
        } catch (ProviderUnreachableException) {
            // expected -- the caller still sees the error this tick
        }

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(RunStatus::Running, $persisted->getStatus());
        // Only the warm-up's batch banked; the failed wave advanced nothing.
        self::assertSame(1, $persisted->getProgress()->batchesDone);
        self::assertSame(1, $persisted->getTransportFailures());
        // Warm-up, then all three calls of the wave fired, even though only
        // one failed.
        self::assertCount(4, $this->stubChatClient()->calls());

        // The next tick re-runs the very same batch indices from the unmoved
        // cursor -- three fresh usable replies bank all three.
        foreach ([$batches[1], $batches[2], $batches[3]] as $batch) {
            $this->stubChatClient()->queueContent(json_encode([
                'recommendations' => [['id' => $batch[0], 'score' => 90, 'reason' => 'rerun']],
            ], \JSON_THROW_ON_ERROR));
        }
        $rerun = $this->advancer()->advance($this->user, TickDriver::Worker);
        self::assertSame(4, $rerun->batchesDone);
    }

    /** A rejected key never produced a reply either, so it counts against the same one-per-wave ceiling. */
    public function testCredentialsRejectedInWaveAlsoCountsTheCeiling(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency(3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(new CredentialsRejectedException('bad key'));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->advancer()->advance($this->user, TickDriver::Worker);
            self::fail('The wave transport failure must propagate.');
        } catch (CredentialsRejectedException) {
            // expected -- the caller still sees the error this tick
        }

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(RunStatus::Running, $persisted->getStatus());
        self::assertSame(0, $persisted->getProgress()->batchesDone);
        self::assertSame(1, $persisted->getTransportFailures());
    }

    /** Concurrency 1, even under the worker driver, advances a multi-batch run one batch per tick. */
    public function testConcurrencyOneTakesTheSequentialPath(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $firstTick = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(1, $firstTick->batchesDone);
        self::assertCount(1, $this->stubChatClient()->calls());
        self::assertFalse($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 80, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));
        $secondTick = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(2, $secondTick->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls());
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);
    }

    /**
     * A poll tick clamps concurrency to POLL_MAX_CONCURRENCY: after the warm-up wave three batches remain, the next
     * wave sends two, and the batch left over shows that the clamp, not the plan length, held it.
     */
    public function testPollDriverClampsConcurrencyToTwo(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Poll);

        // The next poll wave sends two of the three remaining batches.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'b']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame(3, $report->batchesDone);
        self::assertCount(3, $this->stubChatClient()->calls()); // warm-up, then two clamped calls
        self::assertFalse($this->activeRun()->getProgress()->isConsolidationPhase);
    }

    public function testSweepDriverClampsConcurrencyToTwo(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Sweep);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Sweep);

        // The next sweep wave sends two of the three remaining batches.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'b']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Sweep);

        self::assertSame(3, $report->batchesDone);
        self::assertCount(3, $this->stubChatClient()->calls()); // warm-up, then two clamped calls
        self::assertFalse($this->activeRun()->getProgress()->isConsolidationPhase);
    }

    /**
     * Mid-plan the wave clamps to the batches actually left, not the connection's cap. It needs a later tick: on the
     * first the cursor is zero, so a sign error in the subtraction would not show.
     */
    public function testWaveClampsToTheBatchesActuallyLeftOnALaterTick(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(2);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // The fan-out wave of two: batches 1 and 2.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 90, 'reason' => 'b']],
        ], \JSON_THROW_ON_ERROR));
        $secondTick = $this->advancer()->advance($this->user, TickDriver::Worker);
        self::assertSame(3, $secondTick->batchesDone);

        // One batch left, cap 2: the last wave must send exactly one call, not
        // overshoot to a fifth, nonexistent batch index.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));
        $thirdTick = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(4, $thirdTick->batchesDone);
        self::assertCount(4, $this->stubChatClient()->calls()); // warm-up, two, then one
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);
    }

    /**
     * A poll tick's plan never blocks, so a 429 in its fan-out wave defers: the batch phase halves the wave concurrency
     * and RecommendationRunDeferral defers the run, with no strike and nothing banked past the warm-up batch.
     */
    public function testAPollBatchWaveDefersAndHalvesTheConcurrencyOnA429(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll); // snapshot
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Poll); // warm-up wave banks batch 0

        // The clamped wave (2 of the 3 remaining batches) is one concurrent round: the first call meets a 429, and
        // the second's usable answer is discarded too, since a deferral settles every call the round opened.
        $this->stubChatClient()->queueFailure(new RetryableProviderException(429, 20));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 92, 'reason' => 'discarded']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame('running', $report->status);
        $run = $this->activeRun();
        self::assertSame(0, $run->getTransportFailures());     // deferral, not a strike
        self::assertNotNull($run->getRetryNotBefore());
        self::assertSame(1, $run->getProgress()->batchesDone);    // nothing new banked (warm-up only)
        self::assertSame(2, $run->getWaveConcurrencyCap(4));      // halved from 4
    }

    /**
     * The worker's plan retries in-tick: a 429 still halves the concurrency, but the wave banks everything, with no
     * strike and no deferral. Only the limited call re-fires, and Retry-After 0 keeps the retry's sleep at zero.
     */
    public function testAWorkerBatchWaveHalvesButStillBanksWhenTheRetryRecovers(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker); // warm-up

        // Fan-out over batches 1..3: batch 1 is limited once, then recovers on the re-fire; 2 and 3 answer first time.
        $this->stubChatClient()->queueFailure(new RetryableProviderException(429, 0));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 92, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 93, 'reason' => 'three']],
        ], \JSON_THROW_ON_ERROR));
        // Retry re-fires only the limited call (batch 1).
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 91, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(4, $report->batchesDone);              // whole wave banked
        self::assertSame(0, $this->activeRun()->getTransportFailures());
        self::assertSame(2, $this->activeRun()->getWaveConcurrencyCap(4)); // halved once
    }

    public function testAWorkerBatchWaveStillRateLimitedAfterItsRetriesHalvesAndCountsATransportFailure(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        $this->stubChatClient()->queueFailure(new RetryableProviderException(429, 0));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 92, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 93, 'reason' => 'three']],
        ], \JSON_THROW_ON_ERROR));
        for ($retry = 0; $retry < 3; $retry++) {
            $this->stubChatClient()->queueFailure(new RetryableProviderException(429, 0));
        }

        try {
            $this->advancer()->advance($this->user, TickDriver::Worker);
            self::fail('A rate limit that outlasts the retries must propagate.');
        } catch (RetryableProviderException) {
        }

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(1, $persisted->getProgress()->batchesDone);
        self::assertSame(1, $persisted->getTransportFailures());
        self::assertSame(2, $persisted->getWaveConcurrencyCap(4));
    }

    /** The API cannot store a batchConcurrency of 0; one written directly to the DB still must not wedge the run. */
    public function testZeroBatchConcurrencyStillAdvancesOneBatch(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];
        $this->setBatchConcurrency(0);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'floor']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(1, $report->batchesDone);
        self::assertCount(1, $this->stubChatClient()->calls());
    }

    public function testZeroBatchConcurrencyStillAdvancesOneBatchPerWaveAfterTheFirst(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);
        $this->setBatchConcurrency(0);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 80, 'reason' => 'floor']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(2, $report->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls()); // the warm-up wave, then one floored call
    }

    public function testZeroBatchConcurrencyStillAdvancesOneBatchPerPollWaveAfterTheFirst(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);
        $this->setBatchConcurrency(0);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Poll);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 80, 'reason' => 'floor']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame(2, $report->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls());
    }

    public function testTheBatchCallCarriesTheAccountsReasoningPreference(): void
    {
        $this->seedReadyAiSettings($this->user);
        $config = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($config);
        $config->setSuppressReasoning(false);
        $this->entityManager->flush();

        for ($index = 0; $index < 3; $index++) {
            $this->entry('entry-' . $index, 60 - $index);
        }
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        // An empty ranking is unusable, so it retries in-tick: one reply per attempt degrades the single batch within
        // the one tick. The reasoning flag this test pins rides on every call, first included.
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('{"recommendations":[]}');
        }
        $this->advancer()->advance($this->user); // batch tick

        $calls = $this->stubChatClient()->calls();
        self::assertNotSame([], $calls);
        self::assertFalse($calls[0]['suppressReasoning']);
    }

    /**
     * An unusable reply and its corrective retry are one tick, and the tail quotes that batch's own last invalid
     * reply, not the run's cross-tick lastInvalidReply.
     */
    public function testInvalidReplyTriggersCorrectiveRetryInTheSameTick(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent('not json');
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame(1, $report->batchesDone);

        $calls = $this->stubChatClient()->calls();
        self::assertCount(2, $calls); // this batch's two attempts
        $secondCallMessages = $calls[1]['messages'];
        self::assertCount(4, $secondCallMessages);
        self::assertSame('assistant', $secondCallMessages[2]['role']);
        self::assertSame('not json', $secondCallMessages[2]['content']);
        self::assertSame('user', $secondCallMessages[3]['role']);
        self::assertStringContainsString('Your previous reply was not usable.', $secondCallMessages[3]['content']);
    }

    /**
     * A runaway is the model's failure, not the endpoint's: it costs its one batch a retry, never the wave a
     * transport-failure strike, which re-ran the identical prompt for three hours to learn nothing (#437).
     */
    public function testARunawayRetriesItsOwnBatchInsteadOfFailingTheWave(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        $this->stubChatClient()->queueFailure(new ProviderRunawayException('would not stop', '{"recomm'));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame('running', $report->status);
        self::assertSame(1, $report->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls()); // this batch's two attempts

        $this->entityManager->clear();
        self::assertSame(0, $this->activeRun()->getTransportFailures());
    }

    public function testTheRetryAfterARunawayShowsTheModelWhereItWentWrong(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        $this->stubChatClient()->queueFailure(
            new ProviderRunawayException('would not stop', str_repeat('{"id": 349500}, ', 4000)),
        );
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $this->advancer()->advance($this->user);

        $retryMessages = $this->stubChatClient()->calls()[1]['messages'];
        self::assertCount(4, $retryMessages);
        self::assertSame('assistant', $retryMessages[2]['role']);
        self::assertStringContainsString('{"id": 349500}', $retryMessages[2]['content']);
        self::assertLessThan(4096, \strlen($retryMessages[2]['content']));
    }

    /**
     * A batch the model cannot rank after every retry is dropped, not fatal: the batches that did rank still reach
     * the reader.
     */
    public function testAPersistentlyUnusableBatchIsDroppedNotFatal(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $secondBatch = $run->getCandidateBatches()[1];

        // The three attempts for the first batch all run inside one tick, so one advance drops it.
        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');
        $afterDrop = $this->advancer()->advance($this->user);

        // The run kept going: the empty batch counts as done, batch two is
        // still owed -- it did not fail on the exhausted attempts.
        self::assertSame('running', $afterDrop->status);
        self::assertSame(1, $afterDrop->batchesDone);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'kept']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->queueConsolidationReply([['id' => $secondBatch[0], 'score' => 90, 'reason' => 'kept']]);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        // Only batch two's winner survives; the dropped batch contributes none.
        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame(
            [$secondBatch[0]],
            array_map(fn (RecommendationItem $item): int => $this->entryIdOf($item), $items),
        );
    }

    public function testResumeAfterFailureRetriesTheFailedBatchNotTheFirst(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        // Batch two fails at the transport ceiling, the run's one fatal path, which leaves a resumable failure there.
        for ($index = 0; $index < RecommendationRun::MAX_TRANSPORT_FAILURES; $index++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected -- the tick re-throws so the caller sees the error
            }
        }
        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());

        // resume(), not start(), continues a failed run; start() would begin fresh at batch one.
        $this->starter()->resume($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame(2, $report->batchesDone);
        $calls = $this->stubChatClient()->calls();
        $lastCall = array_pop($calls);
        self::assertNotNull($lastCall);
        self::assertStringContainsString('- [' . $secondBatch[0], $lastCall['messages'][1]['content']);
    }

    public function testProviderExceptionLeavesTheRunUntouched(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));

        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected a ProviderUnreachableException.');
        } catch (ProviderUnreachableException) {
            // expected
        }

        $this->entityManager->clear();
        $run = $this->activeRun();
        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertSame(0, $run->getProgress()->batchesDone);
        self::assertFalse($run->getProgress()->attemptsExhausted);
        self::assertNull($run->getLastInvalidReply());
        // Below the transport-failure ceiling: recorded, but the run stays
        // running (asserted above) so the next tick retries the same batch.
    }

    /**
     * An unreachable provider never yields a reply, so attemptsExhausted never fires: without its own ceiling the run
     * would wedge forever.
     */
    public function testConsecutiveTransportFailuresReachingTheCeilingFailTheRun(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        for ($index = 0; $index < RecommendationRun::MAX_TRANSPORT_FAILURES - 1; $index++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected -- the tick re-throws so the caller sees the error
            }
        }

        $this->entityManager->clear();
        self::assertSame(RunStatus::Running, $this->activeRun()->getStatus());

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('still down'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected a ProviderUnreachableException.');
        } catch (ProviderUnreachableException) {
            // expected -- still re-thrown even once the run is failed
        }

        $this->entityManager->clear();
        $run = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        // The run error carries the last transport failure's own detail, not a hardcoded "could not be reached": the
        // provider was reached, and refused.
        $error = $run->getError();
        self::assertNotNull($error);
        self::assertStringContainsString('still down', $error);
        self::assertStringContainsString('https://api.example.test/v1', $error);
        self::assertNull($this->runs()->findActiveForUser($this->user));
    }

    /** A success between transport failures must not carry the old count
     *  into a later run of bad luck. */
    public function testABatchWinBetweenTransportFailuresResetsTheCounter(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected a ProviderUnreachableException.');
        } catch (ProviderUnreachableException) {
            // expected
        }

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->entityManager->clear();
        $run = $this->activeRun();
        self::assertSame(1, $run->getProgress()->batchesDone);

        // Two more failures: had the earlier one not been reset, this would
        // already be at the ceiling.
        for ($index = 0; $index < RecommendationRun::MAX_TRANSPORT_FAILURES - 1; $index++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down again'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected
            }
        }

        $this->entityManager->clear();
        self::assertSame(RunStatus::Running, $this->activeRun()->getStatus());
    }

    public function testPrunedBatchSkipsWithoutAProviderCall(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];
        $callsBeforeThisTick = \count($this->stubChatClient()->calls());

        foreach ($firstBatch as $entryId) {
            $entry = $this->entityManager->getRepository(Entry::class)->find($entryId);
            self::assertNotNull($entry);
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $report = $this->advancer()->advance($this->user);

        self::assertSame(1, $report->batchesDone);
        self::assertCount($callsBeforeThisTick, $this->stubChatClient()->calls());

        // Proves the empty winner set was actually flushed, not just set on
        // the in-memory entity the report happens to read from.
        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(1, $persisted->getProgress()->batchesDone);
        self::assertSame([], $persisted->getWinners()[0]);
    }

    /**
     * Every plan checkpoints once its last batch is done, so the tick after a fully pruned single batch sees the
     * consolidation phase, finds an empty winner pool and finalizes it for free, without a provider call.
     */
    public function testSingleBatchRunWithEveryEntryPrunedCompletesInsteadOfWedging(): void
    {
        $this->seedSingleBatchFixture(picksLimit: 2);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        $run = $this->activeRun();
        self::assertSame(2, $run->getProgress()->batchesTotal); // 1 batch + consolidate

        foreach ($run->getCandidateBatches()[0] as $entryId) {
            $entry = $this->entityManager->getRepository(Entry::class)->find($entryId);
            self::assertNotNull($entry);
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $afterPrunedBatch = $this->advancer()->advance($this->user); // batch tick: fully pruned, no call

        self::assertSame('running', $afterPrunedBatch->status);
        self::assertSame([], $this->stubChatClient()->calls());

        $report = $this->advancer()->advance($this->user); // consolidate tick: empty pool, finalizes for free

        self::assertSame('completed', $report->status);
        self::assertSame([], $this->stubChatClient()->calls());
        self::assertNull($this->runs()->findActiveForUser($this->user));
        self::assertCount(0, $this->recommendationItems($run));

        // The tick after is where the wedge showed: it re-entered a phase and
        // died on an index or a state the frozen plan does not have.
        self::assertSame('completed', $this->advancer()->advance($this->user)->status);
    }

    /**
     * The wave over batches 1..3 resolves positions 1 and 3 at once and 2 in round 2, so winners arrive as [1, 3, 2]:
     * returned unsorted, batch 2's winners would land in batch 3's slot.
     */
    public function testWaveWinnersStayInPlanOrderWhenAMiddleBatchRetries(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // The fan-out wave over batches 1..3. Round 1 resolves positions 1 and
        // 3 straight away; only position 2's winner is added later, in round 2.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 91, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent('not json');
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 93, 'reason' => 'three']],
        ], \JSON_THROW_ON_ERROR));
        // Round 2, fired for position 2 alone -- its winner is added last.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 92, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(4, $report->batchesDone);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $winners = $this->activeRun()->getWinners();
        self::assertSame($batches[0][0], $winners[0][0]['id']);
        self::assertSame($batches[1][0], $winners[1][0]['id']);
        self::assertSame($batches[2][0], $winners[2][0]['id']);
        self::assertSame($batches[3][0], $winners[3][0]['id']);
    }

    /**
     * A batch pruned down to *most, but not all* of its ids still calls the
     * provider — only the fully-pruned case is free — and the prompt must
     * not mention the id that dropped out.
     */
    public function testPartiallyPrunedBatchStillCallsTheProviderWithoutTheDroppedId(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];
        $droppedId = $firstBatch[1];

        $entry = $this->entityManager->getRepository(Entry::class)->find($droppedId);
        self::assertNotNull($entry);
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame(1, $report->batchesDone);
        $calls = $this->stubChatClient()->calls();
        self::assertCount(1, $calls);
        $userMessage = $calls[0]['messages'][1]['content'];
        self::assertStringContainsString('- [' . $firstBatch[0], $userMessage);
        self::assertStringNotContainsString('- [' . $droppedId . ']', $userMessage);
    }

    /**
     * A fully pruned batch in the middle of a wave must not stop the batches after it: each is still sent, in plan
     * order, beside the pruned one's free empty winner set.
     */
    public function testPrunedBatchInTheMiddleOfAWaveDoesNotSkipTheBatchesAfterIt(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        // The warm-up wave banks batch 0 alone.
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'warm']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user, TickDriver::Worker);

        // Prune batch 2 entirely; it sits in the middle of the fan-out wave.
        foreach ($batches[2] as $entryId) {
            $entry = $this->entityManager->getRepository(Entry::class)->find($entryId);
            self::assertNotNull($entry);
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        $batches = $this->activeRun()->getCandidateBatches();

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[3][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(4, $report->batchesDone);
        // Warm-up, then only the two not-pruned batches call the provider.
        self::assertCount(3, $this->stubChatClient()->calls());
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $winners = $this->activeRun()->getWinners();
        self::assertSame([], $winners[2]);
    }

    /**
     * A single-batch run still spends a consolidation call, and the final list's order, score and reason come from
     * the consolidation reply.
     */
    public function testSingleBatchRunStillRunsConsolidationBeforeFinalizing(): void
    {
        $this->seedReadyAiSettings($this->user);
        for ($index = 0; $index < 5; $index++) {
            $this->entry('entry-' . $index, 60 - $index);
        }
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        $run = $this->activeRun();
        self::assertSame(2, $run->getProgress()->batchesTotal); // 1 batch + consolidate
        $batch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $batch[1], 'score' => 55, 'reason' => 'weaker match'],
                ['id' => $batch[0], 'score' => 90, 'reason' => 'stronger match'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $afterBatch = $this->advancer()->advance($this->user); // batch tick

        self::assertSame('running', $afterBatch->status);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->queueConsolidationReply([
            ['id' => $batch[0], 'score' => 90, 'reason' => 'stronger match'],
            ['id' => $batch[1], 'score' => 55, 'reason' => 'weaker match'],
        ]);
        $report = $this->advancer()->advance($this->user); // consolidate tick

        self::assertSame('completed', $report->status);
        self::assertCount(2, $this->stubChatClient()->calls()); // batch, consolidate

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([1, 2], array_map(static fn (RecommendationItem $item): int => $item->getPosition(), $items));
        self::assertSame([$batch[0], $batch[1]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
        self::assertSame(['stronger match', 'weaker match'], array_map(
            static fn (RecommendationItem $item): string => $item->getReason(),
            $items,
        ));
        self::assertSame([90, 55], array_map(
            static fn (RecommendationItem $item): ?int => $item->getScore(),
            $items,
        ));
    }

    public function testConsolidateTickDropsNamedDuplicatesAndFinalizesInScoreOrder(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstBatch[0], 'score' => 80, 'reason' => 'from batch one'],
                ['id' => $firstBatch[1], 'score' => 60, 'reason' => 'also batch one'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $secondBatch[0], 'score' => 95, 'reason' => 'from batch two'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $afterBatches = $this->advancer()->advance($this->user);
        self::assertSame('running', $afterBatches->status);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->queueConsolidationReply(
            [
                ['id' => $secondBatch[0], 'score' => 95, 'reason' => 'from batch two'],
                ['id' => $firstBatch[0], 'score' => 80, 'reason' => 'from batch one'],
            ],
            [$firstBatch[1]],
        );
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $consolidateCall = $this->stubChatClient()->calls()[2]; // batch one, batch two, consolidate
        self::assertStringContainsString(
            'You rank a candidate list of unread posts',
            $consolidateCall['messages'][0]['content'],
        );
        $consolidateUserMessage = $consolidateCall['messages'][1]['content'];
        self::assertStringContainsString('CANDIDATES', $consolidateUserMessage);
        // Score order, not batch order: batch two's 95 outranks batch one's 80.
        self::assertMatchesRegularExpression(
            \sprintf('/\[%d\].*\n.*\[%d\].*\n.*\[%d\]/', $secondBatch[0], $firstBatch[0], $firstBatch[1]),
            $consolidateUserMessage,
        );

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
        self::assertSame(['from batch two', 'from batch one'], array_map(
            static fn (RecommendationItem $item): string => $item->getReason(),
            $items,
        ));
    }

    /**
     * A consolidation transport failure counts once against the run's ceiling, keeps the run RUNNING and re-throws;
     * the next tick retries the call from the unchanged consolidation phase.
     */
    #[DataProvider('transportFailureArms')]
    public function testTransportFailureDuringConsolidateCallCountsTheCeilingAndKeepsRunRunning(
        \RuntimeException $transportFailure,
    ): void {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 80, 'reason' => 'batch one']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'batch two']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->stubChatClient()->queueFailure($transportFailure);

        try {
            $this->advancer()->advance($this->user);
            self::fail('The consolidation transport failure must propagate.');
        } catch (ProviderUnreachableException | CredentialsRejectedException) {
            // expected -- the caller still sees the error this tick
        }

        $this->entityManager->clear();
        $persisted = $this->activeRun();
        self::assertSame(RunStatus::Running, $persisted->getStatus());
        self::assertSame(1, $persisted->getTransportFailures());
        self::assertTrue(
            $persisted->getProgress()->isConsolidationPhase,
            'A failed consolidation call leaves the phase to retry.',
        );
        self::assertSame([], $this->recommendationItems($persisted));
    }

    public function testARejectedConsolidationCallFailsTheRunAtOnce(): void
    {
        $this->seedMultiBatchFixture();
        $this->fixtures->stopSuppressingReasoning($this->user);
        $run = $this->startAndSnapshot();
        foreach ($run->getCandidateBatches() as $batch) {
            $this->stubChatClient()->queueContent(json_encode([
                'recommendations' => [['id' => $batch[0], 'score' => 80, 'reason' => 'kept']],
            ], \JSON_THROW_ON_ERROR));
            $this->advancer()->advance($this->user);
        }
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);
        $this->stubChatClient()->queueFailure(
            new ProviderRejectedRequestException(422, 'That provider refused the request (status 422): No.'),
        );

        $report = $this->advancer()->advance($this->user);

        self::assertSame(RunStatus::Failed->value, $report->status);
        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: '
            . 'That provider refused the request (status 422): No.',
            $failed->getError(),
        );
        self::assertSame(0, $failed->getTransportFailures());
        self::assertSame([], $this->recommendationItems($failed));
    }

    /**
     * A 429 on a poll tick's consolidation call defers the run, still RUNNING in the consolidation phase: no strike,
     * and no finalizing on the degraded pool.
     */
    public function testAPollConsolidationTickDefersOnA429WithoutStriking(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 80, 'reason' => 'batch one']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'batch two']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->stubChatClient()->queueFailure(new RetryableProviderException(429, 30));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame(RunStatus::Running->value, $report->status);
        $persisted = $this->activeRun();
        self::assertSame(0, $persisted->getTransportFailures());
        self::assertNotNull($persisted->getRetryNotBefore());
        self::assertTrue($persisted->getProgress()->isConsolidationPhase);
    }

    /**
     * Both transport-failure arms: a rejected key never produced a reply either, so it counts against the same
     * ceiling.
     *
     * @return iterable<string, array{0: \RuntimeException}>
     */
    public static function transportFailureArms(): iterable
    {
        yield 'provider unreachable' => [new ProviderUnreachableException('provider down')];
        yield 'credentials rejected' => [new CredentialsRejectedException('bad key')];
    }

    public function testConsolidateTickWithAllWinnersPrunedFinalizesWithoutAProviderCall(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'from batch one']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'from batch two']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->entityManager->clear();
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        foreach ([$firstBatch[0], $secondBatch[0]] as $winnerId) {
            $entry = $this->entityManager->getRepository(Entry::class)->find($winnerId);
            self::assertNotNull($entry);
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertCount(2, $this->stubChatClient()->calls()); // batch one, batch two -- no consolidate call
        $this->entityManager->clear();
        self::assertCount(0, $this->recommendationItems($run));
    }

    public function testConsolidationInputIsCutToTwiceThePicksLimitAcrossTheWholePool(): void
    {
        $this->seedMultiBatchFixture(picksLimit: 4);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        $run = $this->activeRun();
        self::assertCount(2, $run->getCandidateBatches());
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        // Batch one scores low across the board, batch two high: the global
        // cut must keep the eight best over BOTH batches, not per batch.
        $run->recordBatchWinners(array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 10, 'reason' => 'low ' . $id],
            $firstBatch,
        ));
        $run->recordBatchWinners(array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 90, 'reason' => 'high ' . $id],
            $secondBatch,
        ));
        $this->entityManager->flush();
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        $this->queueConsolidationReply(array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 90, 'reason' => 'high ' . $id],
            $secondBatch,
        ));
        $this->advancer()->advance($this->user);

        $consolidateUserMessage = $this->stubChatClient()->calls()[0]['messages'][1]['content'];
        // 2 × picksLimit(4) = 8 lines survive the cut — and because batch two
        // outscores batch one everywhere, all 8 come from batch two.
        self::assertSame(8, substr_count($consolidateUserMessage, "\n- ["));
        self::assertSame(8, $this->lineCountForBatch($consolidateUserMessage, $secondBatch));
        self::assertSame(0, $this->lineCountForBatch($consolidateUserMessage, $firstBatch));

        // The consolidation call named no duplicates, so the cut to the picks
        // limit is the only thing that can bring those 8 survivors down to 4.
        $this->entityManager->clear();
        self::assertCount(4, $this->recommendationItems($run));
    }

    /**
     * A reply naming almost every pooled id as a duplicate is read as a mistake, not obeyed: the run spends its
     * retries, then completes with the undeduped batch-score list.
     */
    public function testAConsolidationReplyNamingEveryPooledIdIsRejectedAndTheRunDegrades(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 60, 'reason' => 'weaker']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'best of all']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $overFlagging = json_encode(
            [
                'recommendations' => [
                    ['id' => $firstBatch[0], 'score' => 60, 'reason' => 'weaker'],
                    ['id' => $secondBatch[0], 'score' => 95, 'reason' => 'best of all'],
                ],
                'duplicates' => [$firstBatch[0], $secondBatch[0]],
            ],
            \JSON_THROW_ON_ERROR,
        );
        $this->stubChatClient()->queueContent($overFlagging);
        $this->stubChatClient()->queueContent($overFlagging);
        $this->stubChatClient()->queueContent($overFlagging);

        self::assertSame('running', $this->advancer()->advance($this->user)->status);
        self::assertSame('running', $this->advancer()->advance($this->user)->status);

        // The retry asks for the consolidation phase's own correction, not
        // the batch phase's "use only candidate ids".
        $retryMessages = $this->stubChatClient()->calls()[3]['messages']; // batch one, batch two, attempt one
        self::assertSame($overFlagging, $retryMessages[2]['content']);
        self::assertSame(RecommendationPromptText::CONSOLIDATION_CORRECTIVE, $retryMessages[3]['content']);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    /** The batch call scores every candidate it is shown, so only the finalizer cuts the ranked pool to picksLimit. */
    public function testSingleBatchRunTruncatesTheRankedPoolToThePicksLimit(): void
    {
        $this->seedSingleBatchFixture(picksLimit: 2);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        $run = $this->activeRun();
        self::assertSame(2, $run->getProgress()->batchesTotal); // 1 batch + consolidate
        $batch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $batch[0], 'score' => 30, 'reason' => 'third'],
                ['id' => $batch[1], 'score' => 70, 'reason' => 'second'],
                ['id' => $batch[2], 'score' => 95, 'reason' => 'first'],
                ['id' => $batch[3], 'score' => 10, 'reason' => 'fourth'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user); // batch tick

        $this->queueConsolidationReply([
            ['id' => $batch[0], 'score' => 30, 'reason' => 'third'],
            ['id' => $batch[1], 'score' => 70, 'reason' => 'second'],
            ['id' => $batch[2], 'score' => 95, 'reason' => 'first'],
            ['id' => $batch[3], 'score' => 10, 'reason' => 'fourth'],
        ]);
        $report = $this->advancer()->advance($this->user); // consolidate tick

        self::assertSame('completed', $report->status);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([$batch[2], $batch[1]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    /**
     * The consolidation call has its own runaway path, ending as the batch phase's does: an unusable reply, then the
     * undeduped batch-score list, never an exception escaping the tick.
     */
    public function testARunawayConsolidateReplyDegradesInsteadOfEscapingTheTick(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->stubChatClient()->queueFailure(new ProviderRunawayException('would not stop', '{"dup'));
        }

        $this->advancer()->advance($this->user);
        $this->advancer()->advance($this->user);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        // A runaway is the model's failure, so it never touches the transport
        // ceiling — the endpoint answered, and at length.
        $this->entityManager->clear();
        self::assertSame(0, $this->persistedTransportFailures($run));
    }

    public function testThreeUnusableConsolidationRepliesCompleteTheRunUndeduped(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');

        $this->advancer()->advance($this->user);
        $secondTry = $this->advancer()->advance($this->user);
        self::assertSame('running', $secondTry->status);

        // The retry carries the corrective tail, same as a batch retry.
        // calls()[0] and [1] are the two batches.
        $retryMessages = $this->stubChatClient()->calls()[3]['messages'];
        self::assertCount(4, $retryMessages);
        self::assertSame('garbage 1', $retryMessages[2]['content']);

        // Read from the database: an unpersisted retry counter restarts at zero on every poll, so the degrade ending
        // never arrives and each poll spends one more provider call on the run.
        $this->entityManager->clear();
        self::assertSame(2, $this->persistedAttempts($run));

        $report = $this->advancer()->advance($this->user);

        // Degraded, not failed: the batches' ranking work is kept and the
        // run completes with the undeduped score-ordered list.
        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    public function testConsolidationRepliesTheProviderKeepsCuttingCompleteTheRunWithTheFinishedPicks(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $cutReply = new CallProgressModel(
            sprintf(
                '{"recommendations": [{"id": %d, "score": 640, "reason": "Finished."}, {"id": %d, "sc',
                $firstBatch[0],
                $secondBatch[0],
            ),
            100,
            'error',
        );
        for ($attempt = 1; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueStreamedReply($cutReply);
            self::assertSame('running', $this->advancer()->advance($this->user)->status);
        }

        $this->stubChatClient()->queueStreamedReply($cutReply);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
        self::assertSame('Finished.', $items[0]->getReason());
        self::assertSame(640, $items[0]->getScore());
    }

    /**
     * The degrade ending still owes the reader the list size they asked for:
     * the consolidation pool is cut to twice the picks limit, so completing
     * straight from it would hand back up to double that.
     */
    public function testTheDegradedConsolidationEndingStillCutsThePoolToThePicksLimit(): void
    {
        $this->seedMultiBatchFixture(picksLimit: 2);
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        $run = $this->activeRun();
        self::assertCount(2, $run->getCandidateBatches());

        foreach ($run->getCandidateBatches() as $batch) {
            $run->recordBatchWinners(array_map(
                static fn (int $id): array => ['id' => $id, 'score' => 50, 'reason' => 'pooled ' . $id],
                $batch,
            ));
        }
        $this->entityManager->flush();
        self::assertTrue($this->activeRun()->getProgress()->isConsolidationPhase);

        for ($attempt = 1; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('garbage ' . $attempt);
            $this->advancer()->advance($this->user);
        }

        $this->stubChatClient()->queueContent('the garbage that exhausts the attempts');
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $this->entityManager->clear();
        // 2 × picksLimit(2) = 4 entries reached the consolidation call, so a
        // pool handed back whole would be twice the list the reader asked for.
        self::assertCount(2, $this->recommendationItems($run));
    }

    /**
     * An entry deleted between its batch call and the consolidation call is
     * dropped from the ranked pool, so the model never sees it and it never
     * reaches the final list. The survivors still land at dense positions.
     */
    public function testAnEntryPrunedBeforeTheConsolidationCallNeverReachesTheFinalList(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1'],
                ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r3']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $prunedId = $firstBatch[0];
        $prunedEntry = $this->entityManager->getRepository(Entry::class)->find($prunedId);
        self::assertNotNull($prunedEntry);
        $this->entityManager->remove($prunedEntry);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $run = $this->activeRun();
        $this->queueConsolidationReply([
            ['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r3'],
            ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
        ]);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        // calls()[0] and [1] are the two batches.
        $consolidateUserMessage = $this->stubChatClient()->calls()[2]['messages'][1]['content'];
        self::assertStringNotContainsString('[' . $prunedId . ']', $consolidateUserMessage);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([1, 2], array_map(static fn (RecommendationItem $item): int => $item->getPosition(), $items));
        self::assertSame([$secondBatch[0], $firstBatch[1]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    public function testBatchAndConsolidateCallsAreLoggedWithVerdicts(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->queueConsolidationReply([
            ['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1'],
            ['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2'],
        ]);
        $this->advancer()->advance($this->user);

        $rows = $this->logRowsOfLatestRun();
        self::assertSame(
            [
                [CallPhase::Batch, 1, CallVerdict::Usable],
                [CallPhase::Batch, 2, CallVerdict::Usable],
                [CallPhase::Consolidate, null, CallVerdict::Usable],
            ],
            array_map(
                static fn (array $row): array => [$row['phase'], $row['batchNumber'], $row['verdict']],
                $rows,
            ),
        );
        $batchLog = $this->freshRunLog($rows[0]['id']);
        self::assertStringContainsString('You score candidate posts', $batchLog->getRequestBody());
        // json_encode() with no pretty-print flag (StubChatClient's queued
        // content, unlike the pretty-printed request body) has no space
        // after the colon; the log must store the reply verbatim.
        self::assertStringContainsString('"score":70', $batchLog->getResponseText());
    }

    public function testACorrectiveRetryGetsItsOwnLogRowWithTheUnusableVerdict(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();

        // The unusable reply and its corrective retry are one tick, so a single advance consumes both queued replies,
        // and each still gets its own log row with the right verdict.
        $firstEntryId = $run->getCandidateBatches()[0][0];
        $this->stubChatClient()->queueContent('not json');
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstEntryId, 'score' => 50, 'reason' => 'r']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $rows = $this->logRowsOfLatestRun();
        self::assertSame([1, 2], array_column($rows, 'attempt'));
        self::assertSame([CallVerdict::Unusable, CallVerdict::Usable], array_column($rows, 'verdict'));
        self::assertSame('not json', $this->freshRunLog($rows[0]['id'])->getResponseText());
        self::assertStringContainsString(
            'Your previous reply was not usable.',
            $this->freshRunLog($rows[1]['id'])->getRequestBody(),
        );
    }

    public function testATransportFailureStampsItsLogRow(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('gone'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('The transport failure must propagate.');
        } catch (ProviderUnreachableException) {
        }

        $rows = $this->logRowsOfLatestRun();
        self::assertSame([CallVerdict::TransportFailed], array_column($rows, 'verdict'));
        $log = $this->freshRunLog($rows[0]['id']);
        self::assertSame('gone', $log->getErrorDetail());
        self::assertNotNull($log->getFinishedAt());
    }

    public function testABatchCallsStreamReportsReachItsLogRow(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();

        $this->stubChatClient()->queueStreamedReply(new CallProgressModel(
            json_encode([
                'recommendations' => [['id' => $run->getCandidateBatches()[0][0], 'score' => 50, 'reason' => 'r']],
            ], \JSON_THROW_ON_ERROR),
            512,
            'stop',
        ));
        $this->advancer()->advance($this->user);

        $rows = $this->logRowsOfLatestRun();
        self::assertSame([CallVerdict::Usable], array_column($rows, 'verdict'));
        self::assertSame(['stop'], array_column($rows, 'finishReason'));
        self::assertSame([512], array_column($rows, 'wireBytes'));
    }

    public function testApiKeyUnreadableSettlesTheLogRowInsteadOfLeavingItStreamingForever(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $keyDonor = (new UserFactory($this->entityManager, $this->passwordHasher()))->create('key-donor@example.test');
        $this->fixtures->seedReadyAiSettings($keyDonor);
        $this->deleteAiSettings();
        // The donor's key is sealed under the donor's account id, so on $this->user it fails its integrity check. The
        // active pointer is set on this in-memory $this->user, not by pointActiveAt()'s DQL: moveOwnership() clears
        // the entity manager, which detaches $this->user, so a database-only write would never reach it.
        $moved = (new AiSettingsRowMover($this->entityManager))->moveOwnership($keyDonor, $this->user);
        $this->user->setActiveAiProviderSettings($moved);
        // The tick reads the run's own user, which the advance reloads from this identity map.
        $reloadedUser = $this->entityManager->find(User::class, $this->user->requireId());
        self::assertNotNull($reloadedUser);
        $reloadedUser->setActiveAiProviderSettings($moved);

        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected an AiKeyUnreadableException.');
        } catch (AiKeyUnreadableException) {
        }

        $rows = $this->logRowsOfLatestRun();
        self::assertSame([CallVerdict::TransportFailed], array_column($rows, 'verdict'));
        $log = $this->freshRunLog($rows[0]['id']);
        self::assertNotNull($log->getErrorDetail());
        self::assertNotNull($log->getFinishedAt());
    }

    /**
     * @return list<RecommendationItem>
     */
    private function recommendationItems(RecommendationRun $run): array
    {
        /** @var list<RecommendationItem> $items */
        $items = $this->entityManager
            ->getRepository(RecommendationItem::class)
            ->findBy(['run' => $run], ['position' => 'ASC']);

        return $items;
    }

    /** The counter as the database holds it: the entity manager could serve the in-memory value under test. */
    private function persistedTransportFailures(RecommendationRun $run): int
    {
        $failures = $this->entityManager->getConnection()->fetchOne(
            'SELECT transport_failures FROM recommendation_run WHERE id = ?',
            [$run->getId()],
        );
        self::assertIsNumeric($failures);

        return (int) $failures;
    }

    private function persistedAttempts(RecommendationRun $run): int
    {
        $attempts = $this->entityManager->getConnection()->fetchOne(
            'SELECT attempts FROM recommendation_run WHERE id = ?',
            [$run->getId()],
        );
        self::assertIsNumeric($attempts);

        return (int) $attempts;
    }

    private function entryIdOf(RecommendationItem $item): int
    {
        $id = $item->getEntry()->getId();
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @param list<int> $batchIds
     */
    private function lineCountForBatch(string $consolidateUserMessage, array $batchIds): int
    {
        return array_sum(array_map(
            static fn (int $id): int => substr_count($consolidateUserMessage, '[' . $id . ']'),
            $batchIds,
        ));
    }

    /**
     * Twenty candidates at MULTI_BATCH_CONTEXT_WINDOW pack into two batches of 10. Each user of this fixture pins that
     * split after its snapshot tick, so a change to the packing maths fails loudly instead of going single-batch.
     */
    private function seedMultiBatchFixture(
        int $picksLimit = RecommendationSettings::DEFAULT_PICKS_LIMIT,
    ): void {
        $this->seedReadyAiSettings($this->user);

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        for ($index = 0; $index < self::MULTI_BATCH_ENTRY_COUNT; $index++) {
            $entry = $this->entry(
                sprintf('entry-%02d', $index),
                1440 - $index,
            );
            $entry->setSummary($summary);
        }
        $this->entityManager->flush();

        $this->persistSettings(self::MULTI_BATCH_ENTRY_COUNT, $picksLimit);
    }

    /**
     * Five candidates always pack into one batch — the packer only splits
     * once a batch holds MINIMUM_BATCH_SIZE (10) — so this fixture drives
     * the single-batch ending regardless of the context window.
     */
    private function seedSingleBatchFixture(int $picksLimit): void
    {
        $this->seedReadyAiSettings($this->user);

        for ($index = 0; $index < self::SINGLE_BATCH_ENTRY_COUNT; $index++) {
            $this->entry('entry-' . $index, 60 - $index);
        }
        $this->entityManager->flush();

        $this->persistSettings(self::SINGLE_BATCH_ENTRY_COUNT, $picksLimit);
    }

    private function persistSettings(int $candidatePoolSize, int $picksLimit): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: new RecommendationPoolLimits(
                $candidatePoolSize,
                RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
                $picksLimit,
            ),
            contextWindow: self::MULTI_BATCH_CONTEXT_WINDOW,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    /**
     * Forces an exact batch count through the connection's per-batch ceiling. Each cap stays under
     * MINIMUM_BATCH_SIZE, below which the token budget never splits, so the context window cannot change the count.
     */
    private function seedForcedBatchCountFixture(int $entryCount, int $batchCount): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->fixtures->capBatchesAt($this->user, (int) ceil($entryCount / $batchCount));

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        for ($index = 0; $index < $entryCount; $index++) {
            $entry = $this->entry(
                sprintf('entry-%02d', $index),
                1440 - $index,
            );
            $entry->setSummary($summary);
        }
        $this->entityManager->flush();

        $this->persistSettings($entryCount, RecommendationSettings::DEFAULT_PICKS_LIMIT);
    }

    /**
     * Sets the connection's per-batch concurrency: the default seeded row is 1,
     * so a wave test opts in to the fan-out here.
     */
    private function setBatchConcurrency(int $concurrency): void
    {
        $config = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($config);
        $config->setBatchConcurrency($concurrency);
        $this->entityManager->flush();
    }

    private function persistedConnection(): AiProviderSettings
    {
        $connection = $this->entityManager->getRepository(AiProviderSettings::class)
            ->findOneBy(['user' => $this->user]);
        self::assertNotNull($connection);

        return $connection;
    }

    /** Drives a run through the snapshot tick, pinning the two-batch split on the way. */
    private function startAndSnapshot(): RecommendationRun
    {
        $this->storeProfile('a distilled profile');
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();

        self::assertSame(3, $run->getProgress()->batchesTotal); // 2 batches + consolidate
        self::assertCount(2, $run->getCandidateBatches());
        self::assertCount(10, $run->getCandidateBatches()[0]);
        self::assertCount(10, $run->getCandidateBatches()[1]);

        return $run;
    }

    private function storeProfile(string $profileText): void
    {
        $this->fixtures->storeProfile($this->user, $profileText);
    }

    /**
     * The consolidation reply: the batch phase's recommendations envelope plus a duplicates list.
     *
     * @param list<array{id: int, score: int, reason: string}> $recommendations
     * @param list<int>                                        $duplicates
     */
    private function queueConsolidationReply(array $recommendations, array $duplicates = []): void
    {
        $this->stubChatClient()->queueContent(json_encode(
            ['recommendations' => $recommendations, 'duplicates' => $duplicates],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private function activeRun(): RecommendationRun
    {
        $run = $this->runs()->findActiveForUser($this->user);
        self::assertNotNull($run);

        return $run;
    }

    private function entry(string $guid, int $minutesAgo): Entry
    {
        return $this->fixtures->entry($this->feed, $guid, $minutesAgo);
    }

    private function seedReadyAiSettings(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);
    }

    /**
     * The log rows of the account's newest run: the log keeps ten runs, so a read names one.
     *
     * @return list<DebugLogRow>
     */
    private function logRowsOfLatestRun(): array
    {
        $run = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($run);

        return $this->runLogs()->listForRun($this->user, $run->requireId());
    }

    private function runLogs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $repository;
    }

    private function freshRunLog(int $id): RecommendationRunLog
    {
        $this->entityManager->clear();
        $log = $this->entityManager->getRepository(RecommendationRunLog::class)->find($id);
        self::assertNotNull($log);

        return $log;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->entityManager->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    /**
     * The user stops the run while a tick is inside a paid provider call: the tick must not record the result. The
     * stop is written straight to the database, as a web request would while a worker ticks, because cancelling
     * through the service would stop the run by the entity's own status guard instead.
     */
    public function testARunStoppedDuringAProviderCallDoesNotRecordThatCallsResult(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];

        $runId = $run->requireId();
        $this->stubChatClient()->duringNextCall(function () use ($runId): void {
            $this->entityManager->getConnection()->update(
                'recommendation_run',
                ['status' => 'cancelled', 'completed_at' => '2026-01-01 00:00:00'],
                ['id' => $runId],
            );
        });
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame('cancelled', $report->status);

        $this->entityManager->clear();
        $persisted = $this->runRepository()->findLatestForUser($this->user);
        self::assertNotNull($persisted);
        self::assertSame(RunStatus::Cancelled, $persisted->getStatus());
        self::assertSame(0, $persisted->getProgress()->batchesDone);
        self::assertSame([], $persisted->getWinners());
    }

    public function testAStatusPollDuringTheFirstBatchCallSeesTheFirstBatchStarted(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $runId = $run->requireId();
        self::assertFalse($this->persistedFirstBatchStarted($runId));

        $startedDuringTheCall = null;
        $this->stubChatClient()->duringNextCall(function () use ($runId, &$startedDuringTheCall): void {
            $startedDuringTheCall = $this->persistedFirstBatchStarted($runId);
        });
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $run->getCandidateBatches()[0][0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));

        $this->advancer()->advance($this->user);

        self::assertTrue($startedDuringTheCall);
    }

    private function persistedFirstBatchStarted(int $runId): bool
    {
        $started = $this->entityManager->getConnection()->fetchOne(
            'SELECT first_batch_started FROM recommendation_run WHERE id = ?',
            [$runId],
        );
        self::assertIsNumeric($started);

        return 1 === (int) $started;
    }

    private function runRepository(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $runs */
        $runs = self::getContainer()->get(RecommendationRunRepository::class);

        return $runs;
    }

    private function lockFactory(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = self::getContainer()->get(LockFactory::class);

        return $factory;
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

    private function passwordHasher(): UserPasswordHasherInterface
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return $hasher;
    }
}
