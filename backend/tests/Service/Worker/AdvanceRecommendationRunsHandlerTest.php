<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Factory\ProviderConnectionFactory;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Run\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\TickLockKeepalive;
use App\Service\Recommendation\Run\TickPhases;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use App\Service\Worker\Handler\AdvanceRecommendationRunsHandler;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\AiSettingsRowMover;
use App\Tests\Support\ClearTrackingEntityManager;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TickingClock;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;

/** Real repository, advancer, presence and entity manager: the handler's whole job is coordinating them. */
final class AdvanceRecommendationRunsHandlerTest extends DbTestCase
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

    public function testFiringTouchesTheHeartbeatEvenWithNoRuns(): void
    {
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    /**
     * One heartbeat touch per run: a firing lasts the sum of its runs, so a single touch goes stale and the client
     * takes the working worker for a dead one. A ticking clock makes the touches countable.
     */
    public function testEachRunInAFiringGetsItsOwnHeartbeatTouch(): void
    {
        $first = $this->user('heartbeat-first@example.test');
        $this->fixtures->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('heartbeat-second@example.test');
        $this->fixtures->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        $startedAt = new \DateTimeImmutable('2026-08-08 00:00:00');
        $stepSeconds = 60;
        $this->handlerWithPresenceClock(new TickingClock($startedAt, $stepSeconds))
            ->__invoke(new AdvanceRecommendationRuns());

        self::assertEquals(
            $startedAt->modify(sprintf('+%d seconds', $stepSeconds)),
            $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName()),
        );
    }

    public function testDrivesARunToCompletionAcrossFirings(): void
    {
        $user = $this->user('single-batch@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        // Snapshot firing: moves the run from pending into running with its
        // single batch frozen, and makes no provider call yet.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());
        $run = $this->activeRun($user);
        self::assertSame(RunStatus::Running, $run->getStatus());
        $batch = $run->getCandidateBatches()[0] ?? [];
        self::assertNotSame([], $batch);

        $this->queueDistillReply();

        // Distillation firing: spends the profile call that precedes every batch.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->requeueCleanReplyFor($batch);

        // Batch firing: banks the single batch's winners and checkpoints for
        // the consolidation phase every plan reaches now, one batch or many.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->queueConsolidationReplyFor($batch);

        // Consolidation firing: finalizes the run.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RunStatus::Completed, $persisted->getStatus());
        self::assertNotCount(0, $this->recommendationItems($persisted));
    }

    /**
     * The worker passes TickDriver::Worker and the connection's full batchConcurrency, not the poll clamp of two:
     * after the warm-up wave of one, a single firing fans out all three remaining batches.
     */
    public function testAFiringSendsTheFullWorkerConcurrencyNotThePollClamp(): void
    {
        $user = $this->user('worker-wave@example.test');
        $this->seedForcedBatchCountFixture($user, entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency($user, 3);
        $this->starter()->start($user);

        // Snapshot firing freezes the four-batch plan.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());
        $run = $this->activeRun($user);
        $batches = $run->getCandidateBatches();
        self::assertCount(4, $batches);

        $this->queueDistillReply();

        // Distillation firing: the profile call before any batch.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        // Warm-up firing: batch 0 alone writes the prompt-cache.
        $this->requeueCleanReplyFor($batches[0]);
        $this->handler()->__invoke(new AdvanceRecommendationRuns());
        self::assertSame(1, $this->activeRun($user)->getProgress()->batchesDone);

        // One worker firing then fans out all three remaining batches at once.
        foreach ([$batches[1], $batches[2], $batches[3]] as $batch) {
            $this->requeueCleanReplyFor($batch);
        }
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $persisted = $this->activeRun($user);
        self::assertSame(4, $persisted->getProgress()->batchesDone);
        self::assertTrue($persisted->getProgress()->isConsolidationPhase);
    }

    /**
     * The load-bearing case: one user's dead provider must not stop the
     * sweep from ticking a second user's run in the very same firing.
     */
    public function testProviderFailureIsLoggedAndDoesNotThrow(): void
    {
        $strugglingUser = $this->user('struggling@example.test');
        $this->fixtures->seedSingleBatchFixture($strugglingUser);
        $strugglingRun = $this->startAndSnapshot($strugglingUser);

        $healthyUser = $this->user('healthy@example.test');
        $this->fixtures->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);

        // Runs are processed oldest-first, so the failure the struggling
        // user's own distillation call queues is consumed before the healthy
        // user's own distill reply.
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->queueDistillReply();

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $stillActive = $this->runs()->findActiveForUser($strugglingUser);
        self::assertNotNull($stillActive);
        self::assertSame(RunStatus::Running, $stillActive->getStatus());

        // The healthy run's own tick was not blocked by the struggling one's
        // failure in the same firing: its distillation phase went through.
        $advancedAfterDistill = $this->activeRun($healthyUser);
        self::assertFalse($advancedAfterDistill->getProgress()->distillPending);

        // Fairness is proven above, in the one shared firing. More shared firings would re-tick the struggling run
        // with no reply queued for it, so the healthy run finishes through its own advancer.
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $this->queueConsolidationReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RunStatus::Completed, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));

        $this->assertSoleProviderFailureWarningLogged($logSpy, $strugglingRun->getId());
    }

    /** WorkerRunSweep catches a union of two types; this pins the arm the unreachable-provider case above cannot. */
    public function testCredentialsRejectedIsLoggedAndDoesNotThrow(): void
    {
        $user = $this->user('bad-credentials@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $run = $this->startAndSnapshot($user);

        $this->stubChatClient()->queueFailure(new CredentialsRejectedException('nope'));

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $stillActive = $this->runs()->findActiveForUser($user);
        self::assertNotNull($stillActive);
        self::assertSame(RunStatus::Running, $stillActive->getStatus());

        $this->assertSoleProviderFailureWarningLogged($logSpy, $run->getId());
    }

    /**
     * An unreadable key fails the run with its own message, not AiNotConfiguredException's. No error may be
     * logged: that pins the typed, silent catch rather than WorkerRunSweep's \Throwable floor.
     */
    public function testApiKeyUnreadableFailsTheRunWithItsOwnMessage(): void
    {
        $user = $this->user('key-mismatch@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->startAndSnapshot($user);

        $keyDonor = $this->user('key-mismatch-donor@example.test');
        $this->fixtures->seedReadyAiSettings($keyDonor);

        // The donor's key is sealed under the donor's account id, so moving its row onto $user (whose own row is
        // deleted first) makes the ciphertext fail its integrity check when $user's advance() opens it.
        $this->deleteAiSettingsFor($user);
        $this->moveAiSettingsRow($keyDonor, $user);

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());
        self::assertSame('The stored API key can no longer be read.', $failed->getError());
        self::assertSame([], $logSpy->getRecords());
    }

    /** clear() runs on the successful path; that it also runs when the sweep throws is WorkerRunSweepTest's job. */
    public function testFiringClearsTheIdentityMapAfterwards(): void
    {
        $clearTracker = new ClearTrackingEntityManager($this->entityManager);
        $handler = new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancer(),
                $this->presence(),
                $this->streamHeartbeat($this->presence()),
                $clearTracker,
                new NullLogger(),
            ),
        );

        $handler->__invoke(new AdvanceRecommendationRuns());

        self::assertTrue($clearTracker->wasCleared());
    }

    /**
     * tick() fails and flushes the run before rethrowing, so FAILED alone cannot tell the catches apart: no logged
     * error pins that AiNotConfiguredException landed in the typed, silent catch, not the \Throwable floor.
     */
    public function testUnconfiguredUsersRunIsFailedNotSweptForever(): void
    {
        $user = $this->user('unconfigured@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->startAndSnapshot($user);
        $this->deleteAiSettingsFor($user);

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * A run still PENDING before its first snapshot can lose its AI settings row (DELETE /api/me/ai has no active-run
     * guard), so this test skips startAndSnapshot().
     */
    public function testPendingRunLosingConfigurationBeforeItsFirstSnapshotIsFailed(): void
    {
        $user = $this->user('never-snapshotted@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);
        self::assertSame(RunStatus::Pending, $this->activeRun($user)->getStatus());

        $this->deleteAiSettingsFor($user);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
    }

    /** The same PENDING race, with a healthy user's run sorted after it: that run must still advance in the firing. */
    public function testFairnessWhenAPendingRunFailsBeforeItsFirstSnapshot(): void
    {
        $strugglingUser = $this->user('never-snapshotted-struggling@example.test');
        $this->fixtures->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        self::assertSame(RunStatus::Pending, $this->activeRun($strugglingUser)->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('healthy-after-pending-failure@example.test');
        $this->fixtures->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->queueDistillReply();

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        $failed = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($failed);
        self::assertSame(RunStatus::Failed, $failed->getStatus());

        // Fairness is proven: the healthy run's distillation went through in the firing the pending failure landed
        // in. It finishes through its own advancer now that the struggling run is gone.
        self::assertFalse($this->activeRun($healthyUser)->getProgress()->distillPending);

        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $this->queueConsolidationReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RunStatus::Completed, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));
    }

    /**
     * A flush() that throws while recording one run's failure must not starve the next run. Only the first flush
     * throws (FlushFailingEntityManager); the healthy run then advances through the real EntityManager in that firing.
     */
    public function testFlushFailureRecordingOneRunsFailureDoesNotStarveTheNext(): void
    {
        $strugglingUser = $this->user('flush-failure-struggling@example.test');
        $this->fixtures->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        $strugglingRun = $this->activeRun($strugglingUser);
        self::assertSame(RunStatus::Pending, $strugglingRun->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('flush-failure-healthy@example.test');
        $this->fixtures->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->queueDistillReply();

        $logSpy = new TestHandler();
        $this->handlerWithFlushFailingEntityManager(new Logger('test', [$logSpy]))
            ->__invoke(new AdvanceRecommendationRuns());

        $this->entityManager->clear();
        // The FAILED write still reaches the database, carried by the healthy run's flush of the shared EntityManager.
        // What this pins is that the failing flush() never aborted the loop: the healthy run's flush happened at all.
        $struggling = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($struggling);
        self::assertSame(RunStatus::Failed, $struggling->getStatus());

        self::assertFalse($this->activeRun($healthyUser)->getProgress()->distillPending);

        // The healthy run finishes through its own advancer; the flush resilience is pinned above.
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $this->queueConsolidationReplyFor($healthyRun->getCandidateBatches()[0]);
        $this->advancer()->advance($healthyUser, TickDriver::Worker);

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RunStatus::Completed, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));

        // The flush() failure is no typed provider case: it falls through to the floor, which must log it at error
        // level under its own message.
        self::assertTrue($logSpy->hasErrorRecords());
        $errorRecords = array_values(array_filter(
            $logSpy->getRecords(),
            static fn ($record): bool => Level::Error === $record->level,
        ));
        self::assertCount(1, $errorRecords);
        self::assertSame('Recommendation sweep: unexpected failure advancing a run.', $errorRecords[0]->message);
        self::assertSame(['runId', 'exception'], array_keys($errorRecords[0]->context));
        self::assertSame($strugglingRun->getId(), $errorRecords[0]->context['runId']);
    }

    private function assertSoleProviderFailureWarningLogged(TestHandler $logSpy, ?int $runId): void
    {
        self::assertFalse($logSpy->hasErrorRecords());

        $records = $logSpy->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Recommendation sweep: provider call failed.', $records[0]->message);
        self::assertSame(['runId', 'exception'], array_keys($records[0]->context));
        self::assertSame($runId, $records[0]->context['runId']);
    }

    /** Built by hand around advancerWithFlushFailingEntityManager(); every other collaborator is the container's. */
    private function handlerWithFlushFailingEntityManager(LoggerInterface $logger): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancerWithFlushFailingEntityManager(),
                $this->presence(),
                $this->streamHeartbeat($this->presence()),
                $this->entityManager,
                $logger,
            ),
        );
    }

    /**
     * Only the advancer's own EntityManager fails its first flush: that is the struggling run's fail() write. The
     * phases come from the container, so the healthy run banks through the real EntityManager.
     */
    private function advancerWithFlushFailingEntityManager(): RecommendationRunAdvancer
    {
        return new RecommendationRunAdvancer(
            $this->runs(),
            self::getContainer()->get(LockFactory::class),
            self::getContainer()->get(AiProviderConfigurator::class),
            $this->connectionFactory(),
            self::getContainer()->get(ClockInterface::class),
            new FlushFailingEntityManager($this->entityManager),
            self::getContainer()->get(RecommendationSettingsResolver::class),
            self::getContainer()->get(TickLockKeepalive::class),
            self::getContainer()->get(TickPhases::class),
        );
    }

    /** Built by hand to swap only the logger: a test inspects what was logged without writing to the real log. */
    private function handlerWithLogger(LoggerInterface $logger): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancer(),
                $this->presence(),
                $this->streamHeartbeat($this->presence()),
                $this->entityManager,
                $logger,
            ),
        );
    }

    private function handlerWithPresenceClock(ClockInterface $presenceClock): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancer(),
                new WorkerPresence($this->heartbeats(), $presenceClock),
                $this->streamHeartbeat(new WorkerPresence($this->heartbeats(), $presenceClock)),
                $this->entityManager,
                new NullLogger(),
            ),
        );
    }

    private function deleteAiSettingsFor(User $user): void
    {
        $this->fixtures->deleteAiSettings($user);
    }

    /**
     * Moves the row's owner FK and points $to's active pointer at it: the handler finds the configuration through
     * that pointer and always loads $to fresh, so pointActiveAt()'s database-level write is enough.
     */
    private function moveAiSettingsRow(User $from, User $to): void
    {
        $mover = new AiSettingsRowMover($this->entityManager);
        $moved = $mover->moveOwnership($from, $to);
        $mover->pointActiveAt($to, $moved);
    }

    /** Starts a run and advances it once, so its single batch is frozen and it is RUNNING. */
    private function startAndSnapshot(User $user): RecommendationRun
    {
        $this->starter()->start($user);
        $this->advancer()->advance($user);

        return $this->activeRun($user);
    }

    /**
     * @param list<int> $batchIds
     */
    private function requeueCleanReplyFor(array $batchIds): void
    {
        $this->stubChatClient()->queueContent(json_encode([
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

    /** A canned distill reply: these tests need only the distillation phase to spend its one provider call. */
    private function queueDistillReply(): void
    {
        $this->stubChatClient()->queueContent(json_encode(
            ['profile' => 'a distilled profile'],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * The consolidation phase's reply shape: a usable recommendation for every id the batch phase banked, naming no
     * duplicates.
     *
     * @param list<int> $batchIds
     */
    private function queueConsolidationReplyFor(array $batchIds): void
    {
        $this->stubChatClient()->queueContent(json_encode(
            [
                'recommendations' => array_map(
                    static fn (int $id, int $index): array => [
                        'id' => $id,
                        'score' => 100 - $index,
                        'reason' => 'irrelevant',
                    ],
                    $batchIds,
                    array_keys($batchIds),
                ),
                'duplicates' => [],
            ],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Forces exactly $batchCount batches through the connection's per-batch ceiling, which stays under the token
     * budget's split size, so a worker-regime test can pin how many batches a wave has.
     */
    private function seedForcedBatchCountFixture(User $user, int $entryCount, int $batchCount): void
    {
        $this->fixtures->seedReadyAiSettings($user);
        $this->fixtures->capBatchesAt($user, (int) ceil($entryCount / $batchCount));

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        foreach ($this->fixtures->seedFeedWithEntries($user, $entryCount) as $entry) {
            $entry->setSummary($summary);
        }
        $this->entityManager->flush();

        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: new RecommendationPoolLimits(
                $entryCount,
                RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
                RecommendationSettings::DEFAULT_PICKS_LIMIT,
            ),
            contextWindow: 200000,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        ));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }

    private function setBatchConcurrency(User $user, int $concurrency): void
    {
        $config = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertNotNull($config);
        $config->setBatchConcurrency($concurrency);
        $this->entityManager->flush();
    }

    private function activeRun(User $user): RecommendationRun
    {
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * @return list<RecommendationItem>
     */
    private function recommendationItems(RecommendationRun $run): array
    {
        /** @var list<RecommendationItem> $items */
        $items = $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $run]);

        return $items;
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

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    private function handler(): AdvanceRecommendationRunsHandler
    {
        /** @var AdvanceRecommendationRunsHandler $handler */
        $handler = self::getContainer()->get(AdvanceRecommendationRunsHandler::class);

        return $handler;
    }
    /** Writes only while a completion streams, and StubChatClient never streams: it cannot disturb the mark counts. */
    private function streamHeartbeat(WorkerPresence $presence): SweepStreamHeartbeat
    {
        return new SweepStreamHeartbeat($presence, new MockClock());
    }
    private function connectionFactory(): ProviderConnectionFactory
    {
        /** @var ProviderConnectionFactory $connections */
        $connections = self::getContainer()->get(ProviderConnectionFactory::class);

        return $connections;
    }
}
