<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPromptBuilder;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\TickDriver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiSettingsRowMover;
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
    private const int SINGLE_BATCH_ENTRY_COUNT = 5;
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

        // The output bound travels with the prompt it belongs to: the cap sent
        // is the reserve for exactly the candidates this batch asked about, so
        // a bigger batch gets proportionally more room instead of being
        // truncated by a fixed ceiling. It is the output reserve, not the
        // answer reserve — `max_tokens` bounds reasoning-plus-answer, and a
        // reasoning model starves without the headroom (#327). Derived from the
        // batch here rather than hardcoded, so the assertion still holds if the
        // batch size changes for unrelated reasons.
        self::assertSame(
            $this->promptBuilder()->outputTokenReserve(\count($firstBatch)),
            $calls[0]['maxAnswerTokens'],
        );

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

    /**
     * A wave sized to the whole plan banks every batch in one tick (#344):
     * with concurrency 2 and two batches, one worker tick advances batchesDone
     * by the wave size and records both batches' winners.
     */
    public function testWaveBanksEveryBatchInOneTick(): void
    {
        $this->seedMultiBatchFixture();
        $this->setBatchConcurrency(2);
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 80, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertSame(2, $report->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls());

        $this->em->clear();
        $persisted = $this->activeRun();
        self::assertTrue($persisted->progress()->isDedupPhase);
        self::assertSame(
            [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'one']],
            $persisted->getWinners()[0],
        );
        self::assertSame(
            [['id' => $secondBatch[0], 'score' => 80, 'reason' => 'two']],
            $persisted->getWinners()[1],
        );
    }

    /**
     * An unusable batch in a wave retries in-tick and degrades after
     * MAX_ATTEMPTS without dropping its usable siblings (#344). The wave draws
     * in offset order each round, so the FIFO stub serves: round 1 [A-usable,
     * B-garbage], round 2 [B-garbage], round 3 [B-garbage] -- A once, B three
     * times, all in one tick.
     */
    public function testUnusableBatchRetriesInTickThenDegradesWithoutDroppingSiblings(): void
    {
        $this->seedMultiBatchFixture();
        $this->setBatchConcurrency(2);
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'kept']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');

        $report = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame('running', $report->status);
        self::assertSame(2, $report->batchesDone);
        // A once, B three times -- the retries stayed in-tick.
        self::assertCount(4, $this->stubChatClient()->calls());

        $this->em->clear();
        $persisted = $this->activeRun();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $persisted->getStatus());
        self::assertSame(
            [['id' => $firstBatch[0], 'score' => 90, 'reason' => 'kept']],
            $persisted->getWinners()[0],
        );
        // The stubborn batch degraded to an empty winner set, not fatal.
        self::assertSame([], $persisted->getWinners()[1]);
    }

    /**
     * A transport failure anywhere in a wave is atomic (#344): nothing is
     * banked, the cursor does not move, and the ceiling counts exactly one for
     * the whole wave -- not one per failed call -- so the next tick re-runs the
     * same batches.
     */
    public function testTransportFailureInWaveAdvancesNothingAndIncrementsCeilingOnce(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 3);
        $this->setBatchConcurrency(3);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Worker);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(3, $batches);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[2][0], 'score' => 90, 'reason' => 'c']],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->advancer()->advance($this->user, TickDriver::Worker);
            self::fail('The wave transport failure must propagate.');
        } catch (ProviderUnreachableException) {
            // expected -- the caller still sees the error this tick
        }

        $this->em->clear();
        $persisted = $this->activeRun();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $persisted->getStatus());
        self::assertSame(0, $persisted->progress()->batchesDone);
        self::assertSame([], $persisted->getWinners());
        self::assertSame(1, $persisted->getTransportFailures());
        // All three calls of the wave fired, even though only one failed.
        self::assertCount(3, $this->stubChatClient()->calls());

        // The next tick re-runs the very same batch indices from the unmoved
        // cursor -- three fresh usable replies bank all three.
        foreach ($batches as $batch) {
            $this->stubChatClient()->queueContent(json_encode([
                'recommendations' => [['id' => $batch[0], 'score' => 90, 'reason' => 'rerun']],
            ], \JSON_THROW_ON_ERROR));
        }
        $rerun = $this->advancer()->advance($this->user, TickDriver::Worker);
        self::assertSame(3, $rerun->batchesDone);
    }

    /**
     * Concurrency 1 keeps the pre-#344 behaviour exactly, even under the worker
     * driver: a multi-batch run advances one batch per tick, waveSize 1.
     */
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
        self::assertFalse($this->activeRun()->progress()->isDedupPhase);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 80, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));
        $secondTick = $this->advancer()->advance($this->user, TickDriver::Worker);

        self::assertSame(2, $secondTick->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls());
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);
    }

    /**
     * A poll tick clamps concurrency to POLL_MAX_CONCURRENCY however high the
     * connection is set (#344): with concurrency 4 and four batches, the first
     * poll wave sends two, not four -- and two batches remain, so it is the
     * clamp, not the plan length, that held it.
     */
    public function testPollDriverClampsConcurrencyToTwo(): void
    {
        $this->seedForcedBatchCountFixture(entryCount: 20, batchCount: 4);
        $this->setBatchConcurrency(4);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user, TickDriver::Poll);
        $batches = $this->activeRun()->getCandidateBatches();
        self::assertCount(4, $batches);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[0][0], 'score' => 90, 'reason' => 'a']],
        ], \JSON_THROW_ON_ERROR));
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $batches[1][0], 'score' => 90, 'reason' => 'b']],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user, TickDriver::Poll);

        self::assertSame(2, $report->batchesDone);
        self::assertCount(2, $this->stubChatClient()->calls());
        self::assertFalse($this->activeRun()->progress()->isDedupPhase);
    }

    public function testTheBatchCallCarriesTheAccountsReasoningPreference(): void
    {
        $this->seedReadyAiSettings($this->user);
        $config = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($config);
        $config->setSuppressReasoning(false);
        $this->em->flush();

        for ($i = 0; $i < 3; $i++) {
            $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user); // snapshot tick
        // An empty ranking is unusable, so it retries in-tick (#344); queue one
        // reply per attempt so the single batch degrades within the one tick.
        // The reasoning flag this test pins rides on every call, first included.
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('{"recommendations":[]}');
        }
        $this->advancer()->advance($this->user); // batch tick

        $calls = $this->stubChatClient()->calls();
        self::assertNotSame([], $calls);
        self::assertFalse($calls[0]['suppressReasoning']);
    }

    /**
     * The batch phase retries an unusable reply in-tick now (#344): the
     * unusable reply and its corrective retry are one tick, not two, and the
     * corrective tail is built from that batch's own last invalid reply held
     * in a local map, not the run's cross-tick lastInvalidReply.
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
        self::assertCount(2, $calls);
        $secondCallMessages = $calls[1]['messages'];
        self::assertCount(4, $secondCallMessages);
        self::assertSame('assistant', $secondCallMessages[2]['role']);
        self::assertSame('not json', $secondCallMessages[2]['content']);
        self::assertSame('user', $secondCallMessages[3]['role']);
        self::assertStringContainsString('Your previous reply was not usable.', $secondCallMessages[3]['content']);
    }

    /**
     * A batch the model cannot rank after every retry is dropped, not fatal:
     * the batch phase degrades like the dedup phase already does, so the
     * batches that did rank still reach the reader instead of one stubborn
     * batch throwing the whole run away (#329, seen live with qwen3-vl-4b
     * returning {"recommendations": []} three times for one batch).
     */
    public function testAPersistentlyUnusableBatchIsDroppedNotFatal(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $secondBatch = $run->getCandidateBatches()[1];

        // In-tick now (#344): the three attempts for the first batch all run
        // inside one tick, so one advance drops it -- no longer three ticks.
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
        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        // Only batch two's winner survives; the dropped batch contributes none.
        $this->em->clear();
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

        // Batch two fails at the transport ceiling -- now that an unusable
        // batch degrades instead of failing, this is the run's remaining fatal
        // path, and it is what leaves a resumable failure at batch two.
        for ($i = 0; $i < RecommendationRun::MAX_TRANSPORT_FAILURES; $i++) {
            $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
            try {
                $this->advancer()->advance($this->user);
                self::fail('Expected a ProviderUnreachableException.');
            } catch (ProviderUnreachableException) {
                // expected -- the tick re-throws so the caller sees the error
            }
        }
        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());

        // resume() -- not start() -- is what continues a failed run now; start()
        // would begin fresh at batch one.
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

        $this->em->clear();
        $run = $this->activeRun();
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        self::assertSame(0, $run->progress()->batchesDone);
        self::assertFalse($run->progress()->attemptsExhausted);
        self::assertNull($run->getLastInvalidReply());
        // Below the transport-failure ceiling: recorded, but the run stays
        // running (asserted above) so the next tick retries the same batch.
    }

    /**
     * A provider that is simply unreachable never produces a reply for the
     * parser to judge, so attemptsExhausted (unusable replies) never
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
        // The run error names the real cause, not a hardcoded "could not be
        // reached": the provider was reached on every call and refused each
        // one, and the message that closes the run must say what actually
        // happened -- here the last transport failure's own detail (#329).
        $error = $run->getError();
        self::assertNotNull($error);
        self::assertStringContainsString('still down', $error);
        self::assertStringContainsString('https://api.example.test/v1', $error);
        self::assertNull($this->runs()->findActiveForUser($this->user));
    }

    /**
     * Each provider phase asks for its own structured-output shape: a batch
     * call for the ranking, the dedup call for the duplicate list. Sending one
     * shared schema would let the dedup call demand the ranking shape and fail
     * to parse (#329).
     */
    public function testEachPhaseRequestsItsOwnResponseSchema(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 80, 'reason' => 'one']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 95, 'reason' => 'two']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $calls = $this->stubChatClient()->calls();
        self::assertSame('recommendations', $calls[0]['responseSchemaName']);
        self::assertSame('recommendations', $calls[1]['responseSchemaName']);
        self::assertSame('duplicates', $calls[2]['responseSchemaName']);
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
     * The all-pruned short-circuit must take the same ending as a usable
     * reply. A single-batch run has no dedup call behind it, so merely
     * checkpointing the empty winner set leaves the run running with every
     * batch done — and the next tick reaches for a batch index past the end
     * of the frozen plan, throwing on every poll and on every worker sweep
     * with no terminal state to stop it.
     */
    public function testSingleBatchRunWithEveryEntryPrunedCompletesInsteadOfWedging(): void
    {
        $this->seedSingleBatchFixture(picksLimit: 2);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();
        self::assertSame(1, $run->progress()->batchesTotal);

        foreach ($run->getCandidateBatches()[0] as $entryId) {
            $entry = $this->em->getRepository(Entry::class)->find($entryId);
            self::assertNotNull($entry);
            $this->em->remove($entry);
        }
        $this->em->flush();
        $this->em->clear();

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertSame([], $this->stubChatClient()->calls());
        self::assertNull($this->runs()->findActiveForUser($this->user));
        self::assertCount(0, $this->recommendationItems($run));

        // The tick after is where the wedge showed: it re-entered the batch
        // phase and died on an index the batch plan does not have.
        self::assertSame('completed', $this->advancer()->advance($this->user)->status);
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
        self::assertSame([90, 55], array_map(
            static fn (RecommendationItem $item): ?int => $item->getScore(),
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

        // The dedup call named no duplicates, so the cut to the picks limit
        // is the only thing that can bring those 8 survivors down to 4.
        $this->em->clear();
        self::assertCount(4, $this->recommendationItems($run));
    }

    /**
     * A dedup reply may legally name every id it was shown -- the duplicate
     * parser has no reason to call that unusable. The best-ranked entry
     * cannot duplicate a better-ranked one, though, so it survives and the
     * run still completes with a non-empty list instead of silently writing
     * zero recommendations.
     */
    public function testADedupReplyNamingEveryPooledIdStillKeepsTheTopRankedEntry(): void
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

        $this->stubChatClient()->queueContent(json_encode([
            'duplicates' => [$firstBatch[0], $secondBatch[0]],
        ], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(1, $items);
        self::assertSame($secondBatch[0], $this->entryIdOf($items[0]));
        self::assertSame('best of all', $items[0]->getReason());
    }

    /**
     * The batch call is no longer capped at the picks limit -- it scores
     * every candidate it is shown -- so the single-batch ending is the only
     * place that cuts the ranked pool down to the size the reader asked for.
     */
    public function testSingleBatchRunTruncatesTheRankedPoolToThePicksLimit(): void
    {
        $this->seedSingleBatchFixture(picksLimit: 2);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();
        self::assertSame(1, $run->progress()->batchesTotal);
        $batch = $run->getCandidateBatches()[0];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $batch[0], 'score' => 30, 'reason' => 'third'],
                ['id' => $batch[1], 'score' => 70, 'reason' => 'second'],
                ['id' => $batch[2], 'score' => 95, 'reason' => 'first'],
                ['id' => $batch[3], 'score' => 10, 'reason' => 'fourth'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([$batch[2], $batch[1]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
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

        // Read from the database, not the in-memory entity: an unpersisted
        // retry counter would restart at zero on every poll, so the degrade
        // ending would never arrive and each poll would spend one more
        // provider call on the same run (the spend hazard of #302 and #308).
        $this->em->clear();
        self::assertSame(2, $this->persistedAttempts($run));

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
     * The degrade ending still owes the reader the list size they asked for:
     * the dedup pool is cut to twice the picks limit, so completing straight
     * from it would hand back up to double that.
     */
    public function testTheDegradedDedupEndingStillCutsThePoolToThePicksLimit(): void
    {
        $this->seedMultiBatchFixture(picksLimit: 2);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();
        self::assertCount(2, $run->getCandidateBatches());

        foreach ($run->getCandidateBatches() as $batch) {
            $run->recordBatchWinners(array_map(
                static fn (int $id): array => ['id' => $id, 'score' => 50, 'reason' => 'pooled ' . $id],
                $batch,
            ));
        }
        $this->em->flush();
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        for ($attempt = 1; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueContent('garbage ' . $attempt);
            $this->advancer()->advance($this->user);
        }

        $this->stubChatClient()->queueContent('the garbage that exhausts the attempts');
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $this->em->clear();
        // 2 × picksLimit(2) = 4 entries reached the dedup call, so a pool
        // handed back whole would be twice the list the reader asked for.
        self::assertCount(2, $this->recommendationItems($run));
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

    public function testBatchAndDedupCallsAreLoggedWithVerdictsWhenDebugIsOn(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
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
        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame(
            [['batch', 1, 'usable'], ['batch', 2, 'usable'], ['dedup', null, 'usable']],
            array_map(
                static fn (array $row): array => [$row['phase'], $row['batchNumber'], $row['verdict']],
                $rows,
            ),
        );
        $firstLog = $this->freshRunLog($rows[0]['id']);
        self::assertStringContainsString('You score candidate posts', $firstLog->getRequestBody());
        // json_encode() with no pretty-print flag (StubChatClient's queued
        // content, unlike the pretty-printed request body) has no space
        // after the colon; the log must store the reply verbatim.
        self::assertStringContainsString('"score":70', $firstLog->getResponseText());
    }

    public function testACorrectiveRetryGetsItsOwnLogRowWithTheUnusableVerdict(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $this->startAndSnapshot();

        // In-tick retry (#344): the unusable reply and its corrective retry are
        // one tick, so both queued replies are consumed by a single advance and
        // each still gets its own log row with the right verdict.
        $firstEntryId = $this->activeRun()->getCandidateBatches()[0][0];
        $this->stubChatClient()->queueContent('not json');
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstEntryId, 'score' => 50, 'reason' => 'r']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame([1, 2], array_column($rows, 'attempt'));
        self::assertSame(['unusable', 'usable'], array_column($rows, 'verdict'));
        self::assertSame('not json', $this->freshRunLog($rows[0]['id'])->getResponseText());
        self::assertStringContainsString(
            'Your previous reply was not usable.',
            $this->freshRunLog($rows[1]['id'])->getRequestBody(),
        );
    }

    public function testATransportFailureStampsItsLogRow(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('gone'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('The transport failure must propagate.');
        } catch (ProviderUnreachableException) {
        }

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame(['transport-failed'], array_column($rows, 'verdict'));
        $log = $this->freshRunLog($rows[0]['id']);
        self::assertSame('gone', $log->getErrorDetail());
        self::assertNotNull($log->getFinishedAt());
    }

    public function testNoLogRowsAreWrittenWithDebugOff(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $firstEntryId = $this->activeRun()->getCandidateBatches()[0][0];
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstEntryId, 'score' => 50, 'reason' => 'r']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        self::assertSame([], $this->runLogs()->listForUser($this->user));
    }

    /**
     * A verdict that stays null forever reads to the debug panel as "still
     * streaming" (streamingTextForUser() has no way to tell an abandoned
     * call from a live one) -- so an exception that escapes callProvider()
     * before a reply exists must still settle the row it opened, even one
     * credentials() itself raises before the provider is ever called.
     */
    public function testApiKeyUnreadableSettlesTheLogRowInsteadOfLeavingItStreamingForever(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $this->startAndSnapshot();

        $keyDonor = (new UserFactory($this->em, $this->passwordHasher()))->create('key-donor@example.test');
        $this->fixtures->seedReadyAiSettings($keyDonor);
        $this->deleteAiSettings();
        // The donor's key was sealed under the donor's own account id, so
        // moving its settings row onto $this->user makes the stored
        // ciphertext fail its integrity check the moment credentials() opens
        // it. The active pointer is applied directly on the same in-memory
        // $this->user instance advance() below receives, not through
        // AiSettingsRowMover::pointActiveAt()'s DQL write: settingsFor()
        // resolves through that property directly, and em->clear() (which
        // moveOwnership() calls) detaches $this->user rather than refreshing
        // it, so a database-only write would never become visible to it.
        $moved = (new AiSettingsRowMover($this->em))->moveOwnership($keyDonor, $this->user);
        $this->user->setActiveAiProviderSettings($moved);

        try {
            $this->advancer()->advance($this->user);
            self::fail('Expected an ApiKeyUnreadableException.');
        } catch (ApiKeyUnreadableException) {
        }

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame(['transport-failed'], array_column($rows, 'verdict'));
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
        $items = $this->em->getRepository(RecommendationItem::class)->findBy(['run' => $run], ['position' => 'ASC']);

        return $items;
    }

    /**
     * The retry counter as the database holds it. Read over the connection
     * because the entity exposes no getter, and because reading it through
     * the entity manager would risk serving the very in-memory value this
     * assertion exists to bypass.
     */
    private function persistedAttempts(RecommendationRun $run): int
    {
        $attempts = $this->em->getConnection()->fetchOne(
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
    private function lineCountForBatch(string $dedupUserMessage, array $batchIds): int
    {
        return array_sum(array_map(
            static fn (int $id): int => substr_count($dedupUserMessage, '[' . $id . ']'),
            $batchIds,
        ));
    }

    /**
     * candidatePoolSize 20 with a small context window forces the packer to
     * split into two batches of 10. Every test that uses this fixture pins
     * that split right after its own snapshot tick — through
     * startAndSnapshot(), or with its own assertion on getCandidateBatches()
     * — so a future change to the packing maths fails loudly there instead
     * of silently making these tests single-batch.
     */
    private function seedMultiBatchFixture(
        int $picksLimit = EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
    ): void {
        $this->seedReadyAiSettings($this->user);

        $summary = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 5);
        for ($i = 0; $i < self::MULTI_BATCH_ENTRY_COUNT; $i++) {
            $entry = $this->entry(
                sprintf('entry-%02d', $i),
                sprintf('2026-07-10T%02d:%02d:00Z', intdiv($i, 60), $i % 60),
            );
            $entry->setSummary($summary);
        }
        $this->em->flush();

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

        for ($i = 0; $i < self::SINGLE_BATCH_ENTRY_COUNT; $i++) {
            $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
        $this->em->flush();

        $this->persistSettings(self::SINGLE_BATCH_ENTRY_COUNT, $picksLimit);
    }

    private function persistSettings(int $candidatePoolSize, int $picksLimit, ?int $batchCount = null): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: $candidatePoolSize,
            picksLimit: $picksLimit,
            contextWindow: self::MULTI_BATCH_CONTEXT_WINDOW,
            batchCount: $batchCount,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * Forces an exact batch count through the expert batchCount override, so a
     * wave test can pin how many batches a wave has to work with. ceil(entries
     * / batchCount) is the per-batch cap, and each cap here stays under the
     * MINIMUM_BATCH_SIZE the token budget splits on, so the packer produces
     * exactly $batchCount batches regardless of the context window.
     */
    private function seedForcedBatchCountFixture(int $entryCount, int $batchCount): void
    {
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

        $this->persistSettings($entryCount, EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT, $batchCount);
    }

    /**
     * Sets the connection's per-batch concurrency: the default seeded row is 1,
     * so a wave test opts in to the fan-out here.
     */
    private function setBatchConcurrency(int $concurrency): void
    {
        $config = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $this->user]);
        self::assertNotNull($config);
        $config->setBatchConcurrency($concurrency);
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

    /**
     * Re-updates the RecommendationSettings row seedMultiBatchFixture already
     * persisted, flipping only debugEnabled -- no boolean flag parameter on
     * the fixture helpers themselves.
     */
    private function enableDebug(): void
    {
        /** @var RecommendationSettings $settings */
        $settings = $this->em->getRepository(RecommendationSettings::class)->findOneBy(['user' => $this->user]);
        $current = $settings->values();
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: $current->guidancePrompt,
            favoritesCap: $current->favoritesCap,
            keptCap: $current->keptCap,
            viewedCap: $current->viewedCap,
            candidatePoolSize: $current->candidatePoolSize,
            picksLimit: $current->picksLimit,
            contextWindow: $current->contextWindow,
            batchCount: $current->batchCount,
            debugEnabled: true,
        ));
        $this->em->flush();
    }

    private function runLogs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $repository;
    }

    private function freshRunLog(int $id): RecommendationRunLog
    {
        $this->em->clear();
        $log = $this->em->getRepository(RecommendationRunLog::class)->find($id);
        self::assertNotNull($log);

        return $log;
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

    /**
     * The race the checkpoint exists for: the user stops the run while a tick
     * is inside a provider call that has already been paid for. The tick must
     * not flush its result over the cancellation — without the guard it
     * records the batch and the run marches on, so the button appears to do
     * nothing.
     *
     * The stop is written straight to the database rather than through
     * RecommendationRunCanceller on purpose. The two really do sit in
     * different processes — a worker tick against a web request — so the
     * ticking side holds an entity that still says "running" and cannot learn
     * otherwise by itself. Cancelling through the service here would mutate
     * the very entity under test and the run would stop for the wrong reason:
     * the entity's own status guard, which the real cross-process case never
     * reaches. (RecommendationRunCanceller is covered through POST
     * /api/recommendations/runs/stop in the controller test.)
     */
    public function testARunStoppedDuringAProviderCallDoesNotRecordThatCallsResult(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];

        $runId = $run->getId() ?? 0;
        $this->stubChatClient()->duringNextCall(function () use ($runId): void {
            $this->em->getConnection()->update(
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

        $this->em->clear();
        $persisted = $this->runRepository()->findLatestForUser($this->user);
        self::assertNotNull($persisted);
        self::assertSame('cancelled', $persisted->getStatus());
        self::assertSame(0, $persisted->progress()->batchesDone);
        self::assertSame([], $persisted->getWinners());
    }

    private function runRepository(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $runs */
        $runs = self::getContainer()->get(RecommendationRunRepository::class);

        return $runs;
    }

    private function promptBuilder(): RecommendationPromptBuilder
    {
        /** @var RecommendationPromptBuilder $builder */
        $builder = self::getContainer()->get(RecommendationPromptBuilder::class);

        return $builder;
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
