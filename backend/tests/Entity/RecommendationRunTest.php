<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationRunTest extends TestCase
{
    public function testAFreshPendingRunReportsACoherentProgressSnapshot(): void
    {
        $run = $this->makeRun();

        $progress = $run->getProgress();

        self::assertSame(0, $progress->batchesDone);
        self::assertNull($progress->batchesTotal);
        // Trivially true: zero batches planned, zero batches done.
        self::assertTrue($progress->allBatchCallsDone);
        self::assertSame(0, $progress->nextBatchIndex);
    }

    /** Runs from before the column hold null and ran on the only engine there was. */
    public function testARunWithoutARecordedKindReadsAsTheLlm(): void
    {
        $run = new RecommendationRun(
            new User('legacy-kind@example.test', new \DateTimeImmutable('2026-10-02T09:00:00Z')),
            new \DateTimeImmutable('2026-10-02T09:00:00Z'),
        );

        self::assertSame(RecommendationEngineKind::Llm, $run->getEngineKind());
    }

    public function testSnapshotMovesPendingToRunningAndFixesTheBatchPlan(): void
    {
        $run = $this->makeRun();

        $run->snapshot(RecommendationEngineKind::Llm, [[1, 2], [3]]);

        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertSame([[1, 2], [3]], $run->getCandidateBatches());
        self::assertSame(3, $run->getProgress()->batchesTotal); // 2 batches + consolidate
    }

    public function testASingleBatchPlanTotalsTwoStages(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1, 2, 3]]);

        self::assertSame(2, $run->getProgress()->batchesTotal); // 1 batch + consolidate
    }

    public function testRecordingWinnersAdvancesAndClearsRetryState(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1, 2], [3]]);
        $run->getRunningCallAttempts()->recordInvalidReply('garbage');

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'fresh']]);

        self::assertSame(1, $run->getProgress()->batchesDone);
        self::assertSame([[['id' => 2, 'score' => 50, 'reason' => 'fresh']]], $run->getWinners());
        self::assertNull($run->getLastInvalidReply());
        self::assertFalse($run->getProgress()->attemptsExhausted);
        self::assertSame(1, $run->getProgress()->nextBatchIndex);
    }

    public function testAWinnerRowStoredWithoutAScoreReadsBackAsZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1, 2]]);

        (new \ReflectionProperty(RecommendationRun::class, 'batchWinners'))
            ->setValue($run, [[['id' => 1, 'reason' => 'written before scores existed']]]);

        self::assertSame(
            [[['id' => 1, 'score' => 0, 'reason' => 'written before scores existed']]],
            $run->getWinners(),
        );
    }

    public function testThirdInvalidReplyExhaustsAttempts(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');
        self::assertFalse($run->getProgress()->attemptsExhausted);

        $run->getRunningCallAttempts()->recordInvalidReply('c');

        self::assertTrue($run->getProgress()->attemptsExhausted);
        self::assertSame('c', $run->getLastInvalidReply());
    }

    public function testAllBatchCallsDoneIsFalseUntilEveryBatchReportedWinners(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2]]);

        self::assertFalse($run->getProgress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        self::assertFalse($run->getProgress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'r']]);
        self::assertTrue($run->getProgress()->allBatchCallsDone);
    }

    public function testRecordBatchWinnersResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2]]);
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        $run->getRunningCallAttempts()->recordInvalidReply('c');
        $run->getRunningCallAttempts()->recordInvalidReply('d');
        self::assertFalse($run->getProgress()->attemptsExhausted);
        $run->getRunningCallAttempts()->recordInvalidReply('e');
        self::assertTrue($run->getProgress()->attemptsExhausted);
    }

    public function testResumeResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        $run->getRunningCallAttempts()->recordInvalidReply('c');
        $run->getRunningCallAttempts()->recordInvalidReply('d');
        self::assertFalse($run->getProgress()->attemptsExhausted);
        $run->getRunningCallAttempts()->recordInvalidReply('e');
        self::assertTrue($run->getProgress()->attemptsExhausted);
    }

    public function testAFreshRunHasNotExhaustedItsTransportRetries(): void
    {
        self::assertFalse($this->makeRun()->hasExhaustedTransportRetries());
    }

    public function testThirdTransportFailureExhaustsTheSeparateCeiling(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());

        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testTransportFailuresAndAttemptsCountIndependently(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        $run->getRunningCallAttempts()->recordInvalidReply('garbage');
        $run->getRunningCallAttempts()->recordInvalidReply('garbage');
        $run->getRunningCallAttempts()->recordTransportFailure();

        self::assertFalse($run->getProgress()->attemptsExhausted);
        self::assertFalse($run->hasExhaustedTransportRetries());
    }

    public function testRecordBatchWinnersResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2]]);
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testCompleteClearsExhaustedTransportRetries(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertFalse($run->hasExhaustedTransportRetries());
    }

    public function testResumeResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testResumeIsOnlyLegalFromFailed(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertNull($run->getError());
        self::assertSame([[1]], $run->getCandidateBatches()); // checkpoints survive

        $this->expectException(\LogicException::class);
        $run->resume();
    }

    public function testCompleteStampsAndFillsProgress(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2]]);
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $run->complete($when);

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame($when, $run->getCompletedAt());
        self::assertSame(3, $run->getProgress()->batchesDone); // 2 batches + consolidate
    }

    public function testSnapshotAgainAfterAlreadyRunningThrows(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        $this->expectException(\LogicException::class);
        $run->snapshot(RecommendationEngineKind::Llm, [[2]]);
    }

    public function testCompleteBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
    }

    public function testFailBeforeSnapshotIsLegalAndTerminatesPending(): void
    {
        $run = $this->makeRun();

        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('boom', $run->getError());
    }

    public function testFailAfterAlreadyFailedThrows(): void
    {
        $run = $this->makeRun();
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $this->expectException(\LogicException::class);
        $run->fail('boom again', new \DateTimeImmutable('2026-08-07T10:00:01Z'));
    }

    public function testRecordBatchWinnersBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
    }

    public function testStartsWithNoProviderAndNoSpend(): void
    {
        $run = $this->makeRun();

        self::assertNull($run->getProviderHost());
        self::assertNull($run->getModel());
        self::assertSame(0, $run->getPromptTokens());
        self::assertSame(0, $run->getCompletionTokens());
        self::assertSame(0, $run->getReasoningTokens());
        self::assertSame(0, $run->getCachedTokens());
        self::assertNull($run->getCostNanoCredits());
    }

    public function testStampsTheProviderItWillCall(): void
    {
        $run = $this->makeRun();

        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');

        self::assertSame('openrouter.ai', $run->getProviderHost());
        self::assertSame('x-ai/grok-4-fast', $run->getModel());
    }

    public function testRestampsWhenTheConfigurationChangedBeforeAResume(): void
    {
        $run = $this->makeRun();

        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $run->stampProvider('localhost', 'bonsai-27b');

        self::assertSame('localhost', $run->getProviderHost());
        self::assertSame('bonsai-27b', $run->getModel());
    }

    public function testMarkFirstBatchStartedBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->markFirstBatchStarted();
    }

    public function testCancelAfterAlreadyCompletedThrows(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $this->expectException(\LogicException::class);
        $run->cancel(new \DateTimeImmutable('2026-08-07T10:00:01Z'));
    }

    public function testAFreshRunNeitherWaitsNorReducesTheCap(): void
    {
        $run = $this->makeRun();

        self::assertFalse($run->isRetryDeferredAt(new \DateTimeImmutable('2026-08-07T09:00:00Z')));
        self::assertSame(8, $run->getWaveConcurrencyCap(8));
        self::assertNull($run->getRetryNotBefore());
    }

    public function testAPendingRunHandsOutNoThrottle(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage('Cannot throttle a recommendation run from status "pending".');

        $this->makeRun()->getRunningThrottle();
    }

    public function testAPendingRunHandsOutNoCallAttempts(): void
    {
        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage('Cannot record a call attempt on a recommendation run from status "pending".');

        $this->makeRun()->getRunningCallAttempts();
    }

    public function testARunningRunRecordsAnInvalidReplyThroughItsCallAttempts(): void
    {
        $run = $this->runInRunningState();

        $run->getRunningCallAttempts()->recordInvalidReply('garbage');

        self::assertSame(1, $run->getAttempts());
        self::assertSame('garbage', $run->getLastInvalidReply());
    }

    public function testARunningRunDefersThroughItsThrottle(): void
    {
        $run = $this->runInRunningState();
        $when = new \DateTimeImmutable('2026-08-07T09:05:00Z');

        $run->getRunningThrottle()->deferUntil($when);

        self::assertSame($when, $run->getRetryNotBefore());
        self::assertTrue($run->isRetryDeferredAt(new \DateTimeImmutable('2026-08-07T09:04:00Z')));
        self::assertFalse($run->isRetryDeferredAt($when));
    }

    public function testARunningRunNarrowsItsWaveThroughItsThrottle(): void
    {
        $run = $this->runInRunningState();

        $run->getRunningThrottle()->reduceConcurrency(8);

        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    /** @return iterable<string, array{\Closure(RecommendationRun): void, string}> */
    public static function runEndings(): iterable
    {
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        yield 'complete()' => [static fn (RecommendationRun $run) => $run->complete($when), 'completed'];
        yield 'fail()' => [static fn (RecommendationRun $run) => $run->fail('boom', $when), 'failed'];
        yield 'cancel()' => [static fn (RecommendationRun $run) => $run->cancel($when), 'cancelled'];
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testAThrottleHeldPastTheRunsEndRefusesToDefer(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $throttle = $run->getRunningThrottle();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot defer a recommendation run from status "%s".', $endStatus),
        );

        $throttle->deferUntil(new \DateTimeImmutable('2026-08-07T10:05:00Z'));
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testAThrottleHeldPastTheRunsEndRefusesToNarrowTheWave(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $throttle = $run->getRunningThrottle();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot narrow the wave of a recommendation run from status "%s".', $endStatus),
        );

        $throttle->reduceConcurrency(8);
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testCallAttemptsHeldPastTheRunsEndRefuseAnInvalidReply(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $callAttempts = $run->getRunningCallAttempts();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot record an invalid reply on a recommendation run from status "%s".', $endStatus),
        );

        $callAttempts->recordInvalidReply('garbage');
    }

    /** @param \Closure(RecommendationRun): void $end */
    #[DataProvider('runEndings')]
    public function testCallAttemptsHeldPastTheRunsEndRefuseATransportFailure(\Closure $end, string $endStatus): void
    {
        $run = $this->runInRunningState();
        $callAttempts = $run->getRunningCallAttempts();
        $end($run);

        $this->expectException(InvalidRunStatusException::class);
        $this->expectExceptionMessage(
            sprintf('Cannot record a transport failure on a recommendation run from status "%s".', $endStatus),
        );

        $callAttempts->recordTransportFailure();
    }

    public function testRecordBatchWinnersClearsTheDeferralButKeepsTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    public function testCompleteClearsTheDeferralButKeepsTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    public function testResumeClearsBothTheDeferralAndTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(8, $run->getWaveConcurrencyCap(8));
    }

    public function testAProfileFrozenBeforeTheSnapshotIsTheOneTheRunReads(): void
    {
        $run = $this->makeRun();

        $run->freezeProfile('Likes rail and maps.');
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        self::assertSame('Likes rail and maps.', $run->getProfileText());
    }

    public function testARunningRunCannotFreezeAnotherProfile(): void
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        $this->expectException(InvalidRunStatusException::class);
        $run->freezeProfile('Later profile.');
    }

    private function makeRun(): RecommendationRun
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
    }

    private function runInRunningState(): RecommendationRun
    {
        $run = $this->makeRun();
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        return $run;
    }
}
