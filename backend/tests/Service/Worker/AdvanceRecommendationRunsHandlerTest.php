<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Entity\WorkerHeartbeat;
use App\Repository\EntryRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\ProviderConnectionFactory;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\ChatCompletionClient;
use App\Service\Recommendation\RecommendationBatchWave;
use App\Service\Recommendation\RecommendationCallRecorder;
use App\Service\Recommendation\RecommendationCancellationCheckpoint;
use App\Service\Recommendation\RecommendationCandidateLoader;
use App\Service\Recommendation\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\RecommendationDuplicateParser;
use App\Service\Recommendation\RecommendationHistoryLoader;
use App\Service\Recommendation\RecommendationPromptBuilder;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationWinnerRanker;
use App\Service\Worker\Handler\AdvanceRecommendationRunsHandler;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Service\Worker\SweepStreamHeartbeat;
use Symfony\Component\Clock\MockClock;
use App\Tests\DbTestCase;
use App\Tests\Support\AiSettingsRowMover;
use App\Tests\Support\ClearTrackingEntityManager;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TickingClock;
use App\Tests\Support\UserFactory;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Drives the handler through the container's real repository, advancer,
 * presence and entity manager, the same "no mocks" stance as
 * RecommendationRunAdvancerTest -- the handler's whole job is coordinating
 * those collaborators, and a mock would only re-encode that coordination.
 */
final class AdvanceRecommendationRunsHandlerTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testFiringTouchesTheHeartbeatEvenWithNoRuns(): void
    {
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    /**
     * A firing's duration is the SUM over the runs it ticks, and one run can
     * spend a whole provider timeout, so a single touch at the start of the
     * firing goes stale while the worker is still working. The client then
     * takes the working worker for a dead one, tries to advance the run
     * itself, hits the per-user lock and gives up on a healthy run (#311
     * final review, Critical 2a).
     *
     * A ticking clock makes the number of touches observable: one touch per
     * run, so two runs must leave the heartbeat one step past the start
     * rather than at it. (A firing with nothing to do still touches once --
     * testFiringTouchesTheHeartbeatEvenWithNoRuns covers that path.)
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
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        $batch = $run->getCandidateBatches()[0] ?? [];
        self::assertNotSame([], $batch);

        $this->requeueCleanReplyFor($batch);

        // Batch firing: the single batch finalizes the run directly, with no
        // merge call needed.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted->getStatus());
        self::assertNotCount(0, $this->recommendationItems($persisted));
    }

    /**
     * The worker owns its process, so it passes TickDriver::Worker and sends
     * the connection's full batchConcurrency -- not the poll clamp (#344). With
     * three batches and concurrency three, one batch firing banks all three at
     * once; under the poll clamp of two it would bank only two and still owe a
     * batch. This is what proves the handler passes the worker regime.
     */
    public function testAFiringSendsTheFullWorkerConcurrencyNotThePollClamp(): void
    {
        $user = $this->user('worker-wave@example.test');
        $this->seedForcedBatchCountFixture($user, entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency($user, 3);
        $this->starter()->start($user);

        // Snapshot firing freezes the three-batch plan.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());
        $run = $this->activeRun($user);
        self::assertCount(3, $run->getCandidateBatches());
        foreach ($run->getCandidateBatches() as $batch) {
            $this->requeueCleanReplyFor($batch);
        }

        // One batch firing: the whole wave of three lands in this single tick.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $persisted = $this->activeRun($user);
        self::assertSame(3, $persisted->progress()->batchesDone);
        self::assertTrue($persisted->progress()->isDedupPhase);
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
        // user's run queues is consumed before the healthy user's reply.
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $stillActive = $this->runs()->findActiveForUser($strugglingUser);
        self::assertNotNull($stillActive);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $stillActive->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));

        $this->assertSoleProviderFailureWarningLogged($logSpy, $strugglingRun->getId());
    }

    /**
     * The catch clause this handler uses is a union of two exception types;
     * the ProviderUnreachableException case above alone cannot tell a real
     * union apart from a mutant narrowed to just one arm. This proves the
     * other arm is caught the same way.
     */
    public function testCredentialsRejectedIsLoggedAndDoesNotThrow(): void
    {
        $user = $this->user('bad-credentials@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $run = $this->startAndSnapshot($user);

        $this->stubChatClient()->queueFailure(new CredentialsRejectedException('nope'));

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $stillActive = $this->runs()->findActiveForUser($user);
        self::assertNotNull($stillActive);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $stillActive->getStatus());

        $this->assertSoleProviderFailureWarningLogged($logSpy, $run->getId());
    }

    /**
     * Distinguishes the ApiKeyUnreadableException catch from the
     * AiNotConfiguredException one above it: both fail the run, but each
     * must carry its own message, not the sibling case's.
     *
     * The run's FAILED status alone no longer proves which catch clause
     * handled it (#311 fix): RecommendationRunAdvancer::tick() now fails and
     * flushes the run itself before rethrowing, so even the handler's
     * generic \Throwable floor would see a FAILED run. Asserting no error was
     * logged is what actually pins that ApiKeyUnreadableException landed in
     * the typed, silent catch rather than falling through to that floor.
     */
    public function testApiKeyUnreadableFailsTheRunWithItsOwnMessage(): void
    {
        $user = $this->user('key-mismatch@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->startAndSnapshot($user);

        $keyDonor = $this->user('key-mismatch-donor@example.test');
        $this->fixtures->seedReadyAiSettings($keyDonor);

        // The donor's key was sealed under the donor's own account id; moving
        // its settings row onto $user, whose original (correctly-sealed) row
        // is deleted first, makes the stored ciphertext fail its integrity
        // check the moment $user's advance() tries to open it.
        $this->deleteAiSettingsFor($user);
        $this->moveAiSettingsRow($keyDonor, $user);

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The stored API key can no longer be read.', $failed->getError());
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * The per-firing identity map cleanup is a `finally`, not a plain
     * trailing statement (the rationale now lives on WorkerRunSweep::sweep(),
     * which the handler delegates to); this proves at least that clear()
     * itself is not simply dropped from the successful path. That it also
     * runs when the sweep body throws is WorkerRunSweepTest's job.
     */
    public function testFiringClearsTheIdentityMapAfterwards(): void
    {
        $clearTracker = new ClearTrackingEntityManager($this->em);
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
     * The run's FAILED status alone no longer proves which catch clause
     * handled it (#311 fix): RecommendationRunAdvancer::tick() now fails and
     * flushes the run itself before rethrowing, so even the handler's
     * generic \Throwable floor would see a FAILED run. Asserting no error was
     * logged is what actually pins that AiNotConfiguredException landed in
     * the typed, silent catch rather than falling through to that floor.
     */
    public function testUnconfiguredUsersRunIsFailedNotSweptForever(): void
    {
        $user = $this->user('unconfigured@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->startAndSnapshot($user);
        $this->deleteAiSettingsFor($user);

        $logSpy = new TestHandler();
        $this->handlerWithLogger(new Logger('test', [$logSpy]))->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * Fix round 1 (#311 review): a run that never reached its first snapshot
     * is still PENDING, not RUNNING, when its AI settings row disappears
     * (DELETE /api/me/ai has no "is there an active run" guard). Unlike
     * every test above, this one deliberately skips startAndSnapshot() so
     * the run stays PENDING going into the firing that removes the row.
     */
    public function testPendingRunLosingConfigurationBeforeItsFirstSnapshotIsFailed(): void
    {
        $user = $this->user('never-snapshotted@example.test');
        $this->fixtures->seedSingleBatchFixture($user);
        $this->starter()->start($user);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($user)->getStatus());

        $this->deleteAiSettingsFor($user);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
    }

    /**
     * Fix round 1 (#311 review): the same PENDING-loses-its-settings race as
     * above, but with a second, healthy user's run sorted right after it.
     * Before the fix, the first run's LogicException (from fail() guarding
     * RUNNING) escaped __invoke() entirely and the second user's run was
     * never even attempted in this firing.
     */
    public function testFairnessWhenAPendingRunFailsBeforeItsFirstSnapshot(): void
    {
        $strugglingUser = $this->user('never-snapshotted-struggling@example.test');
        $this->fixtures->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($strugglingUser)->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('healthy-after-pending-failure@example.test');
        $this->fixtures->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));
    }

    /**
     * Fix round 2 (#311 review): an earlier version flushed the
     * fail()-recording write INSIDE the catch that decided to record it, so
     * a flush() failure there (lock timeout, dropped connection) threw from
     * within a catch block -- which PHP never routes to a sibling catch --
     * and escaped exactly like the round-1 LogicException did. That
     * fail()+flush() now lives in RecommendationRunAdvancer::tick() (#311
     * fix, shared with the poll driver), so this test builds its own
     * advancer wired with a decorator that makes only the FIRST flush()
     * throw, without ever invoking the real EntityManager's UnitOfWork (see
     * FlushFailingEntityManager) -- every other collaborator, including the
     * EntityManager underneath the decorator, is the container's real,
     * shared instance, so the second, healthy user's run genuinely advances
     * through the real, un-poisoned EntityManager in the very same firing --
     * the positive assertion, not just "no throw".
     */
    public function testFlushFailureRecordingOneRunsFailureDoesNotStarveTheNext(): void
    {
        $strugglingUser = $this->user('flush-failure-struggling@example.test');
        $this->fixtures->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        $strugglingRun = $this->activeRun($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $strugglingRun->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('flush-failure-healthy@example.test');
        $this->fixtures->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $logSpy = new TestHandler();
        $this->handlerWithFlushFailingEntityManager(new Logger('test', [$logSpy]))
            ->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        // fail() mutated the struggling run's in-memory object before its own
        // flush() threw, and that object stayed managed in the *same* shared
        // EntityManager the healthy run's advance() goes on to flush
        // successfully -- Doctrine computes changesets for every managed
        // entity at flush time, not just the one the caller had in mind, so
        // the FAILED write actually reaches the database anyway, carried by
        // the next successful flush in this firing. The one thing this test
        // exists to prove is the part that is NOT incidental: the failing
        // flush() itself never aborted the loop, so the healthy run's flush
        // still happened at all in the same firing.
        $struggling = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($struggling);
        self::assertSame(RecommendationRun::STATUS_FAILED, $struggling->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));

        // The flush() failure is an unanticipated \Throwable, not one of the
        // typed AI-provider cases the other tests exercise -- it must fall
        // through to the outer floor and be logged at error level under a
        // different message, proving that floor's own logging call (not
        // just its exception-swallowing) survives.
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

    /**
     * Built by hand rather than fetched from the container: the advancer
     * this sweep uses is wired with a decorator whose first flush() throws,
     * so the struggling run's fail()-recording write (now inside
     * RecommendationRunAdvancer::tick(), see the test above) fails exactly
     * once. Every other collaborator -- the repository, and every one of the
     * advancer's own collaborators besides its EntityManager -- is the
     * container's real, shared instance, so the healthy user's run advances
     * through the real EntityManager exactly as it would in production.
     */
    private function handlerWithFlushFailingEntityManager(LoggerInterface $logger): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancerWithFlushFailingEntityManager(),
                $this->presence(),
                $this->streamHeartbeat($this->presence()),
                $this->em,
                $logger,
            ),
        );
    }

    /**
     * Every RecommendationRunAdvancer collaborator except the EntityManager
     * is the container's real, shared instance -- only the flush() that
     * records the struggling run's failure is faked, never the healthy
     * run's own provider call, prompt building or persistence.
     */
    private function advancerWithFlushFailingEntityManager(): RecommendationRunAdvancer
    {
        return new RecommendationRunAdvancer(
            $this->runs(),
            self::getContainer()->get(EntryRepository::class),
            self::getContainer()->get(LockFactory::class),
            self::getContainer()->get(AiProviderConfigurator::class),
            $this->connectionFactory(),
            self::getContainer()->get(ClockInterface::class),
            self::getContainer()->get(RecommendationSettingsResolver::class),
            self::getContainer()->get(RecommendationCandidateLoader::class),
            self::getContainer()->get(RecommendationHistoryLoader::class),
            self::getContainer()->get(RecommendationPromptBuilder::class),
            self::getContainer()->get(ChatCompletionClient::class),
            new FlushFailingEntityManager($this->em),
            self::getContainer()->get(RecommendationWinnerRanker::class),
            self::getContainer()->get(RecommendationDuplicateParser::class),
            self::getContainer()->get(RecommendationCallRecorder::class),
            self::getContainer()->get(RecommendationCancellationCheckpoint::class),
            self::getContainer()->get(RecommendationBatchWave::class),
            self::getContainer()->get(RecommendationCompletionRequestFactory::class),
        );
    }

    /**
     * Built by hand for the same reason as handlerWithFlushFailingEntityManager():
     * only the logger changes, so a test can inspect what was logged without
     * writing to the real log.
     */
    private function handlerWithLogger(LoggerInterface $logger): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            new WorkerRunSweep(
                $this->runs(),
                $this->advancer(),
                $this->presence(),
                $this->streamHeartbeat($this->presence()),
                $this->em,
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
                $this->em,
                new NullLogger(),
            ),
        );
    }

    private function heartbeats(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }

    private function deleteAiSettingsFor(User $user): void
    {
        $this->fixtures->deleteAiSettings($user);
    }

    /**
     * Moves the row's own ownership FK, then points the recipient's active
     * pointer at it too: the handler resolves the active configuration
     * through that pointer, not a "find by owner" query, so a row that ends
     * up under the wrong account only matters to this test once it is also
     * that account's active one. pointActiveAt() writes at the database
     * level, which is enough here because the handler under test always
     * loads $to fresh from the database — it never receives an in-memory
     * instance from this test directly.
     */
    private function moveAiSettingsRow(User $from, User $to): void
    {
        $mover = new AiSettingsRowMover($this->em);
        $moved = $mover->moveOwnership($from, $to);
        $mover->pointActiveAt($to, $moved);
    }

    /**
     * Starts a run and drives one direct advance() call so its single batch
     * is frozen and it is RUNNING -- the same "get to a batch-ready run
     * first" shape RecommendationRunAdvancerTest's own startAndSnapshot()
     * helper uses.
     */
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

    /**
     * Forces an exact batch count through the expert batchCount override, so a
     * worker-regime test can pin how many batches a wave has to work with (the
     * per-batch cap stays under the token budget's split size, so the packer
     * produces exactly $batchCount batches).
     */
    private function seedForcedBatchCountFixture(User $user, int $entryCount, int $batchCount): void
    {
        $this->fixtures->seedReadyAiSettings($user);

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        foreach ($this->fixtures->seedFeedWithEntries($user, $entryCount) as $entry) {
            $entry->setSummary($summary);
        }
        $this->em->flush();

        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $entryCount,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: 2500,
            batchCount: $batchCount,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function setBatchConcurrency(User $user, int $concurrency): void
    {
        $config = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertNotNull($config);
        $config->setBatchConcurrency($concurrency);
        $this->em->flush();
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
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
        $items = $this->em->getRepository(RecommendationItem::class)->findBy(['run' => $run]);

        return $items;
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
    private function connectionFactory(): ProviderConnectionFactory
    {
        /** @var ProviderConnectionFactory $connections */
        $connections = self::getContainer()->get(ProviderConnectionFactory::class);

        return $connections;
    }
}
