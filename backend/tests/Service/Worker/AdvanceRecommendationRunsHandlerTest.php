<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\AiProviderSettings;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\WorkerHeartbeat;
use App\Repository\EntryRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\WorkerHeartbeatRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\ChatCompletionClient;
use App\Service\Recommendation\RecommendationCallRecorder;
use App\Service\Recommendation\RecommendationCandidateLoader;
use App\Service\Recommendation\RecommendationDuplicateParser;
use App\Service\Recommendation\RecommendationHistoryLoader;
use App\Service\Recommendation\RecommendationPickParser;
use App\Service\Recommendation\RecommendationPromptBuilder;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationWinnerRanker;
use App\Service\Worker\Handler\AdvanceRecommendationRunsHandler;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
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

        self::assertTrue($this->presence()->isRecommendationWorkerAlive());
    }

    /**
     * A firing's duration is the SUM over the runs it ticks, and one run can
     * spend a whole provider timeout, so a single touch at the start of the
     * firing goes stale while the worker is still working. The client then
     * takes the working worker for a dead one, tries to advance the run
     * itself, hits the per-user lock and gives up on a healthy run (#311
     * final review, Critical 2a).
     *
     * A ticking clock makes the number of touches observable: one before the
     * loop -- a firing with nothing to do must still report liveness -- plus
     * one per run, so two runs must leave the heartbeat two steps past the
     * start rather than at it.
     */
    public function testEachRunInAFiringGetsItsOwnHeartbeatTouch(): void
    {
        $first = $this->user('heartbeat-first@example.test');
        $this->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('heartbeat-second@example.test');
        $this->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        $startedAt = new \DateTimeImmutable('2026-08-08 00:00:00');
        $stepSeconds = 60;
        $this->handlerWithPresenceClock(new TickingClock($startedAt, $stepSeconds))
            ->__invoke(new AdvanceRecommendationRuns());

        self::assertEquals(
            $startedAt->modify(sprintf('+%d seconds', 2 * $stepSeconds)),
            $this->heartbeats()->findTouchedAt(WorkerPresence::RECOMMENDATION_SWEEP),
        );
    }

    public function testDrivesARunToCompletionAcrossFirings(): void
    {
        $user = $this->user('single-batch@example.test');
        $this->seedSingleBatchFixture($user);
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
     * The load-bearing case: one user's dead provider must not stop the
     * sweep from ticking a second user's run in the very same firing.
     */
    public function testProviderFailureIsLoggedAndDoesNotThrow(): void
    {
        $strugglingUser = $this->user('struggling@example.test');
        $this->seedSingleBatchFixture($strugglingUser);
        $strugglingRun = $this->startAndSnapshot($strugglingUser);

        $healthyUser = $this->user('healthy@example.test');
        $this->seedSingleBatchFixture($healthyUser);
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
        $this->seedSingleBatchFixture($user);
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
        $this->seedSingleBatchFixture($user);
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
     * trailing statement (see the handler's own doc comment); this proves at
     * least that clear() itself is not simply dropped from the successful
     * path.
     */
    public function testFiringClearsTheIdentityMapAfterwards(): void
    {
        $clearTracker = new ClearTrackingEntityManager($this->em);
        $handler = new AdvanceRecommendationRunsHandler(
            $this->runs(),
            $this->advancer(),
            $this->presence(),
            $clearTracker,
            new NullLogger(),
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
        $this->seedSingleBatchFixture($user);
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
        $this->seedSingleBatchFixture($user);
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
        $this->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($strugglingUser)->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('healthy-after-pending-failure@example.test');
        $this->seedSingleBatchFixture($healthyUser);
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
        $this->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        $strugglingRun = $this->activeRun($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $strugglingRun->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('flush-failure-healthy@example.test');
        $this->seedSingleBatchFixture($healthyUser);
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
     * this handler uses is wired with a decorator whose first flush()
     * throws, so the struggling run's fail()-recording write (now inside
     * RecommendationRunAdvancer::tick(), see the test above) fails exactly
     * once. Every other collaborator -- the repository, and every one of the
     * advancer's own collaborators besides its EntityManager -- is the
     * container's real, shared instance, so the healthy user's run advances
     * through the real EntityManager exactly as it would in production.
     */
    private function handlerWithFlushFailingEntityManager(LoggerInterface $logger): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            $this->runs(),
            $this->advancerWithFlushFailingEntityManager(),
            $this->presence(),
            $this->em,
            $logger,
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
            self::getContainer()->get(ClockInterface::class),
            self::getContainer()->get(RecommendationSettingsResolver::class),
            self::getContainer()->get(RecommendationCandidateLoader::class),
            self::getContainer()->get(RecommendationHistoryLoader::class),
            self::getContainer()->get(RecommendationPromptBuilder::class),
            self::getContainer()->get(ChatCompletionClient::class),
            self::getContainer()->get(RecommendationPickParser::class),
            new FlushFailingEntityManager($this->em),
            self::getContainer()->get(RecommendationWinnerRanker::class),
            self::getContainer()->get(RecommendationDuplicateParser::class),
            self::getContainer()->get(RecommendationCallRecorder::class),
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
            $this->runs(),
            $this->advancer(),
            $this->presence(),
            $this->em,
            $logger,
        );
    }

    private function handlerWithPresenceClock(ClockInterface $presenceClock): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            $this->runs(),
            $this->advancer(),
            new WorkerPresence($this->heartbeats(), $presenceClock),
            $this->em,
            new NullLogger(),
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

    private function moveAiSettingsRow(User $from, User $to): void
    {
        $this->em->createQuery(
            sprintf('UPDATE %s s SET s.user = :to WHERE s.user = :from', AiProviderSettings::class),
        )->execute(['to' => $to, 'from' => $from]);
        $this->em->clear();
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

    private function seedSingleBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);

        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $guid = $user->getEmail() . '-entry-' . $i;
            $this->fixtures->entry($feed, $guid, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
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
}
