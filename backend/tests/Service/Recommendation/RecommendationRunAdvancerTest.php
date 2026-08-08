<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository, entity manager and lock factory, not mocks:
 * advance()'s job is to coordinate all of them, and a mock would have to
 * encode that coordination itself instead of proving it.
 */
final class RecommendationRunAdvancerTest extends DbTestCase
{
    private const int MULTI_BATCH_ENTRY_COUNT = 20;
    private const int MULTI_BATCH_CONTEXT_WINDOW = 2500;

    private User $user;
    private Feed $feed;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('run-advancer@example.test');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
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
        for ($i = 0; $i < 5; $i++) {
            $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('running', $report->status);
        self::assertSame(1, $report->batchesTotal);
        self::assertSame(0, $report->batchesDone);
        self::assertSame([], $this->stubChatClient()->calls());

        // Proves the batch plan was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $persisted->getStatus());
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
        self::assertSame(0, $report->batchesTotal);

        // Proves complete() was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted?->getStatus());
    }

    public function testBusyWhenTheLockIsHeld(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

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
    }

    /**
     * Fix #311: RecommendationRun::fail() accepts STATUS_PENDING precisely so
     * a run that never got as far as freezing a candidate pool can still end
     * in a terminal state. Before this fix, that classification lived only
     * in AdvanceRecommendationRunsHandler, so a poll-only install left a
     * PENDING run stuck retried forever the moment its configuration
     * disappeared -- the worker driver would have failed the very same run.
     * The classification now lives in the shared tick(), so the poll driver
     * fails it exactly the way AdvanceRecommendationRunsHandlerTest's
     * testPendingRunLosingConfigurationBeforeItsFirstSnapshotIsFailed proves
     * the worker driver does; the exception still reaches the caller so the
     * controller's HTTP mapping is unchanged.
     */
    public function testAPollTickFailsAPendingRunWhenTheConfigurationDisappears(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->entry('entry-configless', '2026-07-10T00:00:00Z');
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

        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_FAILED, $persisted->getStatus());
        self::assertSame('The AI provider is no longer configured.', $persisted->getError());
    }

    private function deleteAiSettings(): void
    {
        $this->fixtures->deleteAiSettings($this->user);
    }

    /**
     * Pins the ?? 0 fallback in the lock name for an unsaved user (getId()
     * null): pre-acquiring 'ai-recommendations-0' must make advance() busy
     * for such a user, which only holds if the code really names the lock
     * after that fallback and not some other value.
     */
    public function testLockNameFallsBackToZeroForAnUnsavedUser(): void
    {
        $lock = $this->lockFactory()->createLock('ai-recommendations-0');
        self::assertTrue($lock->acquire());

        try {
            $unsavedUser = new User('unsaved@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

            $report = $this->advancer()->advance($unsavedUser);

            self::assertSame('busy', $report->status);
        } finally {
            $lock->release();
        }
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
        self::assertSame('m', $calls[0]['model']);
        self::assertStringContainsString(
            'You score candidate posts for one reader of an RSS reader.',
            $calls[0]['messages'][0]['content'],
        );
        self::assertStringContainsString('- [' . $firstBatch[0], $calls[0]['messages'][1]['content']);

        $this->em->clear();
        $persisted = $this->activeRun();
        self::assertSame(
            [
                ['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1'],
                ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
            ],
            $persisted->getWinners()[0],
        );
    }

    public function testInvalidReplyTriggersCorrectiveRetryNextTick(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent('not json');
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame(1, $report->batchesDone);

        $calls = $this->stubChatClient()->calls();
        self::assertCount(2, $calls);
        $secondCallMessages = $calls[1]['messages'];
        self::assertCount(4, $secondCallMessages);
        self::assertSame('assistant', $secondCallMessages[2]['role']);
        self::assertSame('not json', $secondCallMessages[2]['content']);
        self::assertSame('user', $secondCallMessages[3]['role']);
        self::assertStringContainsString('Your previous reply was not usable.', $secondCallMessages[3]['content']);
    }

    public function testThreeUnusableRepliesFailTheRun(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');

        $this->advancer()->advance($this->user);
        $this->advancer()->advance($this->user);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('failed', $report->status);
        self::assertNotNull($report->error);
        self::assertSame(0, $report->batchesDone);

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame([], $failed->getWinners());
        self::assertTrue($failed->attemptsExhausted());
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

        $this->stubChatClient()->queueContent('bad 1');
        $this->stubChatClient()->queueContent('bad 2');
        $this->stubChatClient()->queueContent('bad 3');
        $this->advancer()->advance($this->user);
        $this->advancer()->advance($this->user);
        $failedReport = $this->advancer()->advance($this->user);
        self::assertSame('failed', $failedReport->status);

        $this->starter()->start($this->user);
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

        $this->em->clear();
        $run = $this->activeRun();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        self::assertSame(0, $run->progress()->batchesDone);
        self::assertFalse($run->attemptsExhausted());
        self::assertNull($run->getLastInvalidReply());
        // Below the transport-failure ceiling: recorded, but the run stays
        // running (asserted above) so the next tick retries the same batch.
    }

    /**
     * A provider that is simply unreachable never produces a reply for the
     * parser to judge, so attemptsExhausted() (unusable replies) never
     * fires. Without its own ceiling, a persistently broken provider would
     * leave the run wedged forever -- no cancel, no reaping (#308 final
     * review, Important 2).
     */
    public function testConsecutiveTransportFailuresReachingTheCeilingFailTheRun(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        for ($i = 0; $i < RecommendationRun::MAX_TRANSPORT_FAILURES - 1; $i++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected -- the tick re-throws so the caller sees the error
            }
        }

        $this->em->clear();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $this->activeRun()->getStatus());

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('still down'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected a ProviderUnreachableException.');
        } catch (ProviderUnreachableException) {
            // expected -- still re-thrown even once the run is failed
        }

        $this->em->clear();
        $run = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($run);
        self::assertSame(RecommendationRun::STATUS_FAILED, $run->getStatus());
        self::assertNotNull($run->getError());
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

        $this->em->clear();
        $run = $this->activeRun();
        self::assertSame(1, $run->progress()->batchesDone);

        // Two more failures: had the earlier one not been reset, this would
        // already be at the ceiling.
        for ($i = 0; $i < RecommendationRun::MAX_TRANSPORT_FAILURES - 1; $i++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down again'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected
            }
        }

        $this->em->clear();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $this->activeRun()->getStatus());
    }

    public function testPrunedBatchSkipsWithoutAProviderCall(): void
    {
        $this->seedMultiBatchFixture();
        $firstBatch = $this->startAndSnapshot()->getCandidateBatches()[0];

        foreach ($firstBatch as $entryId) {
            $entry = $this->em->getRepository(Entry::class)->find($entryId);
            self::assertNotNull($entry);
            $this->em->remove($entry);
        }
        $this->em->flush();
        $this->em->clear();

        $report = $this->advancer()->advance($this->user);

        self::assertSame(1, $report->batchesDone);
        self::assertSame([], $this->stubChatClient()->calls());

        // Proves the empty winner set was actually flushed, not just set on
        // the in-memory entity the report happens to read from.
        $this->em->clear();
        $persisted = $this->activeRun();
        self::assertSame(1, $persisted->progress()->batchesDone);
        self::assertSame([], $persisted->getWinners()[0]);
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

        $entry = $this->em->getRepository(Entry::class)->find($droppedId);
        self::assertNotNull($entry);
        $this->em->remove($entry);
        $this->em->flush();
        $this->em->clear();

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

    public function testSingleBatchRunFinalizesWithoutADedupCallOrderedByScore(): void
    {
        $this->seedReadyAiSettings($this->user);
        for ($i = 0; $i < 5; $i++) {
            $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();
        self::assertSame(1, $run->progress()->batchesTotal);
        $batch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $batch[1], 'score' => 55, 'reason' => 'weaker match'],
                ['id' => $batch[0], 'score' => 90, 'reason' => 'stronger match'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertCount(1, $this->stubChatClient()->calls());

        $this->em->clear();
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
    }

    public function testDedupTickDropsNamedDuplicatesAndFinalizesInScoreOrder(): void
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
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        $this->stubChatClient()->queueContent(json_encode([
            'duplicates' => [$firstBatch[1]],
        ], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $dedupCall = $this->stubChatClient()->calls()[2];
        self::assertStringContainsString('You remove duplicate stories', $dedupCall['messages'][0]['content']);
        $dedupUserMessage = $dedupCall['messages'][1]['content'];
        self::assertStringContainsString('RANKED (best first):', $dedupUserMessage);
        // Score order, not batch order: batch two's 95 outranks batch one's 80.
        self::assertMatchesRegularExpression(
            \sprintf('/\[%d\].*\n.*\[%d\].*\n.*\[%d\]/', $secondBatch[0], $firstBatch[0], $firstBatch[1]),
            $dedupUserMessage,
        );

        $this->em->clear();
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
     * Mirrors providerTick's all-pruned short-circuit (#308 final review,
     * Minor 4): if every winning entry from both batches is gone by the
     * time the dedup runs, there is nothing to ask the model to check, so
     * this is progress, not a call the model would inevitably fail.
     */
    public function testDedupTickWithAllWinnersPrunedFinalizesWithoutAProviderCall(): void
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

        $this->em->clear();
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        foreach ([$firstBatch[0], $secondBatch[0]] as $winnerId) {
            $entry = $this->em->getRepository(Entry::class)->find($winnerId);
            self::assertNotNull($entry);
            $this->em->remove($entry);
        }
        $this->em->flush();
        $this->em->clear();

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertCount(2, $this->stubChatClient()->calls());
        $this->em->clear();
        self::assertCount(0, $this->recommendationItems($run));
    }

    public function testDedupInputIsCutToTwiceThePicksLimitAcrossTheWholePool(): void
    {
        $this->seedMultiBatchFixture(picksLimit: 4);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
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
        $this->em->flush();
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $dedupUserMessage = $this->stubChatClient()->calls()[0]['messages'][1]['content'];
        // 2 × picksLimit(4) = 8 lines survive the cut — and because batch two
        // outscores batch one everywhere, all 8 come from batch two.
        self::assertSame(8, substr_count($dedupUserMessage, "\n- ["));
        self::assertSame(8, $this->lineCountForBatch($dedupUserMessage, $secondBatch));
        self::assertSame(0, $this->lineCountForBatch($dedupUserMessage, $firstBatch));
    }

    public function testThreeUnusableDedupRepliesCompleteTheRunUndeduped(): void
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
        $retryMessages = $this->stubChatClient()->calls()[3]['messages'];
        self::assertCount(4, $retryMessages);
        self::assertSame('garbage 1', $retryMessages[2]['content']);

        $report = $this->advancer()->advance($this->user);

        // Degraded, not failed: the batches' ranking work is kept and the
        // run completes with the undeduped score-ordered list.
        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    /**
     * An entry deleted between its batch call and the dedup call is dropped
     * from the ranked pool, so the model never sees it and it never reaches
     * the final list. The survivors still land at dense positions.
     */
    public function testAnEntryPrunedBeforeTheDedupCallNeverReachesTheFinalList(): void
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
        $prunedEntry = $this->em->getRepository(Entry::class)->find($prunedId);
        self::assertNotNull($prunedEntry);
        $this->em->remove($prunedEntry);
        $this->em->flush();
        $this->em->clear();

        $run = $this->activeRun();
        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $dedupUserMessage = $this->stubChatClient()->calls()[2]['messages'][1]['content'];
        self::assertStringNotContainsString('[' . $prunedId . ']', $dedupUserMessage);

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([1, 2], array_map(static fn (RecommendationItem $item): int => $item->getPosition(), $items));
        self::assertSame([$secondBatch[0], $firstBatch[1]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    /**
     * @return list<RecommendationItem>
     */
    private function recommendationItems(RecommendationRun $run): array
    {
        /** @var list<RecommendationItem> $items */
        $items = $this->em->getRepository(RecommendationItem::class)->findBy(['run' => $run], ['position' => 'ASC']);

        return $items;
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
    private function lineCountForBatch(string $dedupUserMessage, array $batchIds): int
    {
        return array_sum(array_map(
            static fn (int $id): int => substr_count($dedupUserMessage, '[' . $id . ']'),
            $batchIds,
        ));
    }

    /**
     * candidatePoolSize 20 with a small context window forces the packer to
     * split into two batches of 10 (pinned by each test right after its own
     * snapshot tick, via batchesTotal === 3: 2 batches + the dedup slot) so a
     * future change to the packing maths fails loudly here instead of
     * silently making these tests single-batch.
     */
    private function seedMultiBatchFixture(
        int $picksLimit = EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
    ): void {
        $entryCount = self::MULTI_BATCH_ENTRY_COUNT;
        $this->seedReadyAiSettings($this->user);

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $this->entry(
                sprintf('entry-%02d', $i),
                sprintf('2026-07-10T%02d:%02d:00Z', intdiv($i, 60), $i % 60),
            );
            $entry->setSummary($summary);
        }
        $this->em->flush();

        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $entryCount,
            picksLimit: $picksLimit,
            contextWindow: self::MULTI_BATCH_CONTEXT_WINDOW,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * Starts a run and drives the snapshot tick, then pins the fixture's
     * batch count so a future change to the packing maths fails loudly here
     * instead of silently making these tests single-batch.
     */
    private function startAndSnapshot(): RecommendationRun
    {
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();

        self::assertSame(3, $run->progress()->batchesTotal);
        self::assertCount(2, $run->getCandidateBatches());
        self::assertCount(10, $run->getCandidateBatches()[0]);
        self::assertCount(10, $run->getCandidateBatches()[1]);

        return $run;
    }

    private function activeRun(): RecommendationRun
    {
        $run = $this->runs()->findActiveForUser($this->user);
        self::assertNotNull($run);

        return $run;
    }

    private function entry(string $guid, string $published): Entry
    {
        return $this->fixtures->entry($this->feed, $guid, $published);
    }

    private function seedReadyAiSettings(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
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
}
