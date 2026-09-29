<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
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

    public function testSnapshotMovesPendingToRunningAndFixesTheBatchPlan(): void
    {
        $run = $this->makeRun();

        $run->snapshot([[1, 2], [3]]);

        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertSame([[1, 2], [3]], $run->getCandidateBatches());
        self::assertSame(4, $run->getProgress()->batchesTotal); // 2 batches + distill + consolidate
    }

    public function testASingleBatchPlanTotalsThreeStages(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2, 3]]);

        self::assertSame(3, $run->getProgress()->batchesTotal); // 1 batch + distill + consolidate
    }

    public function testRecordingWinnersAdvancesAndClearsRetryState(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2], [3]]);
        $run->getRunningCallAttempts()->recordInvalidReply('garbage');

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'fresh']]);

        self::assertSame(1, $run->getProgress()->batchesDone);
        self::assertSame([[['id' => 2, 'score' => 50, 'reason' => 'fresh']]], $run->getWinners());
        self::assertNull($run->getLastInvalidReply());
        self::assertFalse($run->getProgress()->attemptsExhausted);
        self::assertSame(1, $run->getProgress()->nextBatchIndex);
    }

    /**
     * A run in flight across the deploy that introduced scores holds rows
     * without one. Reading them must not fail: the column defaults them so
     * every consumer sees a scored winner.
     */
    public function testAWinnerRowStoredWithoutAScoreReadsBackAsZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2]]);

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
        $run->snapshot([[1]]);
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
        $run->snapshot([[1], [2]]);

        self::assertFalse($run->getProgress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        self::assertFalse($run->getProgress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'r']]);
        self::assertTrue($run->getProgress()->allBatchCallsDone);
    }

    public function testRecordBatchWinnersResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
        $run->getRunningCallAttempts()->recordInvalidReply('c');
        $run->getRunningCallAttempts()->recordInvalidReply('d');
        self::assertFalse($run->getProgress()->attemptsExhausted);
        $run->getRunningCallAttempts()->recordInvalidReply('e');
        self::assertTrue($run->getProgress()->attemptsExhausted);
    }

    public function testResumeResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
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
        $run->snapshot([[1]]);

        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());

        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    /** Unusable-reply attempts and transport failures are separate counters:
     *  a corrective retry cycle must not push the transport ceiling closer,
     *  and vice versa. */
    public function testTransportFailuresAndAttemptsCountIndependently(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $run->getRunningCallAttempts()->recordInvalidReply('garbage');
        $run->getRunningCallAttempts()->recordInvalidReply('garbage');
        $run->getRunningCallAttempts()->recordTransportFailure();

        self::assertFalse($run->getProgress()->attemptsExhausted);
        self::assertFalse($run->hasExhaustedTransportRetries());
    }

    public function testRecordBatchWinnersResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_TRANSPORT_FAILURES (3) fresh failures are needed to
        // exhaust again — pins the reset at 0, not -1 or 1.
        $run->getRunningCallAttempts()->recordTransportFailure();
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertFalse($run->hasExhaustedTransportRetries());
        $run->getRunningCallAttempts()->recordTransportFailure();
        self::assertTrue($run->hasExhaustedTransportRetries());
    }

    public function testCompleteClearsExhaustedTransportRetries(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
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
        $run->snapshot([[1]]);
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
        $run->snapshot([[1]]);
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
        $run->snapshot([[1], [2]]);
        $when = new \DateTimeImmutable('2026-08-07T10:00:00Z');

        $run->complete($when);

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame($when, $run->getCompletedAt());
        self::assertSame(4, $run->getProgress()->batchesDone);
    }

    public function testSnapshotAgainAfterAlreadyRunningThrows(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $this->expectException(\LogicException::class);
        $run->snapshot([[2]]);
    }

    public function testCompleteBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
    }

    /**
     * #311 fix round 1: an account can lose its AI configuration before its
     * run ever reaches its first snapshot (DELETE /api/me/ai has no "is
     * there an active run" guard), so a run stuck PENDING must still be able
     * to reach a terminal FAILED state instead of guardStatus rejecting the
     * only transition that could ever get it out of PENDING.
     */
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

    public function testRecordProfileStoresTextAndMarksDistilled(): void
    {
        $run = $this->runInRunningState();
        $run->recordProfile('Likes Rust.');

        self::assertTrue($run->isDistilled());
        self::assertSame('Likes Rust.', $run->getProfileText());
    }

    public function testRecordProfileWithNullMarksDistilledButKeepsNoProfile(): void
    {
        $run = $this->runInRunningState();
        $run->recordProfile(null);

        self::assertTrue($run->isDistilled());
        self::assertNull($run->getProfileText());
    }

    public function testFreshRunIsNotDistilled(): void
    {
        self::assertFalse($this->runInRunningState()->isDistilled());
    }

    public function testRecordProfileBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordProfile('Likes Rust.');
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
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $this->expectException(\LogicException::class);
        $run->cancel(new \DateTimeImmutable('2026-08-07T10:00:01Z'));
    }

    public function testRecordProfileResetsAttemptsToExactlyZero(): void
    {
        $run = $this->runInRunningState();
        $run->getRunningCallAttempts()->recordInvalidReply('a');
        $run->getRunningCallAttempts()->recordInvalidReply('b');

        $run->recordProfile('Likes Rust.');

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
        $run->getRunningCallAttempts()->recordInvalidReply('c');
        $run->getRunningCallAttempts()->recordInvalidReply('d');
        self::assertFalse($run->getProgress()->attemptsExhausted);
        $run->getRunningCallAttempts()->recordInvalidReply('e');
        self::assertTrue($run->getProgress()->attemptsExhausted);
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
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot throttle a recommendation run from status "pending".');

        $this->makeRun()->getRunningThrottle();
    }

    public function testAPendingRunHandsOutNoCallAttempts(): void
    {
        $this->expectException(\LogicException::class);
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

    public function testRecordBatchWinnersClearsTheDeferralButKeepsTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    public function testRecordProfileClearsTheDeferralButKeepsTheReducedCap(): void
    {
        $run = $this->runInRunningState();
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $run->recordProfile('Likes Rust.');

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    public function testCompleteClearsTheDeferralButKeepsTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(4, $run->getWaveConcurrencyCap(8));
    }

    public function testResumeClearsBothTheDeferralAndTheReducedCap(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->getRunningThrottle()->reduceConcurrency(8);
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertNull($run->getRetryNotBefore());
        self::assertSame(8, $run->getWaveConcurrencyCap(8));
    }

    private function makeRun(): RecommendationRun
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
    }

    private function runInRunningState(): RecommendationRun
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        return $run;
    }
}
