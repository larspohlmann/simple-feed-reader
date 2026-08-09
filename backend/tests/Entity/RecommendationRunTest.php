<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRun;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RecommendationRunTest extends TestCase
{
    public function testAFreshPendingRunReportsACoherentProgressSnapshot(): void
    {
        $run = $this->makeRun();

        $progress = $run->progress();

        self::assertSame(0, $progress->batchesDone);
        self::assertNull($progress->batchesTotal);
        self::assertFalse($progress->needsDedup);
        self::assertFalse($progress->isDedupPhase);
        // Trivially true: zero batches planned, zero batches done.
        self::assertTrue($progress->allBatchCallsDone);
        self::assertSame(0, $progress->nextBatchIndex);
    }

    public function testSnapshotMovesPendingToRunningAndFixesTheBatchPlan(): void
    {
        $run = $this->makeRun();

        $run->snapshot([[1, 2], [3]]);

        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        self::assertSame([[1, 2], [3]], $run->getCandidateBatches());
        self::assertSame(3, $run->progress()->batchesTotal); // 2 batches + 1 merge
        self::assertTrue($run->progress()->needsDedup);
    }

    public function testASingleBatchNeedsNoMerge(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2, 3]]);

        self::assertSame(1, $run->progress()->batchesTotal);
        self::assertFalse($run->progress()->needsDedup);
    }

    public function testRecordingWinnersAdvancesAndClearsRetryState(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2], [3]]);
        $run->recordInvalidReply('garbage');

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'fresh']]);

        self::assertSame(1, $run->progress()->batchesDone);
        self::assertSame([[['id' => 2, 'score' => 50, 'reason' => 'fresh']]], $run->getWinners());
        self::assertNull($run->getLastInvalidReply());
        self::assertFalse($run->progress()->attemptsExhausted);
        self::assertSame(1, $run->progress()->nextBatchIndex);
        self::assertFalse($run->progress()->isDedupPhase);
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
        $run->recordInvalidReply('a');
        $run->recordInvalidReply('b');
        self::assertFalse($run->progress()->attemptsExhausted);

        $run->recordInvalidReply('c');

        self::assertTrue($run->progress()->attemptsExhausted);
        self::assertSame('c', $run->getLastInvalidReply());
    }

    public function testMergePhaseAfterAllBatchCalls(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'r']]);

        self::assertTrue($run->progress()->isDedupPhase);
    }

    public function testAllBatchCallsDoneIsFalseUntilEveryBatchReportedWinners(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);

        self::assertFalse($run->progress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        self::assertFalse($run->progress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 2, 'score' => 50, 'reason' => 'r']]);
        self::assertTrue($run->progress()->allBatchCallsDone);
    }

    public function testRecordBatchWinnersResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordInvalidReply('a');
        $run->recordInvalidReply('b');

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
        $run->recordInvalidReply('c');
        $run->recordInvalidReply('d');
        self::assertFalse($run->progress()->attemptsExhausted);
        $run->recordInvalidReply('e');
        self::assertTrue($run->progress()->attemptsExhausted);
    }

    public function testResumeResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordInvalidReply('a');
        $run->recordInvalidReply('b');
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
        $run->recordInvalidReply('c');
        $run->recordInvalidReply('d');
        self::assertFalse($run->progress()->attemptsExhausted);
        $run->recordInvalidReply('e');
        self::assertTrue($run->progress()->attemptsExhausted);
    }

    public function testThirdTransportFailureExhaustsTheSeparateCeiling(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());

        self::assertTrue($run->recordTransportFailure());
    }

    /** Unusable-reply attempts and transport failures are separate counters:
     *  a corrective retry cycle must not push the transport ceiling closer,
     *  and vice versa. */
    public function testTransportFailuresAndAttemptsCountIndependently(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);

        $run->recordInvalidReply('garbage');
        $run->recordInvalidReply('garbage');

        self::assertFalse($run->progress()->attemptsExhausted);
        self::assertFalse($run->recordTransportFailure());
    }

    public function testRecordBatchWinnersResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);

        // Exactly MAX_TRANSPORT_FAILURES (3) fresh failures are needed to
        // exhaust again — pins the reset at 0, not -1 or 1.
        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());
        self::assertTrue($run->recordTransportFailure());
    }

    public function testCompleteResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();

        $run->complete(new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        self::assertSame(RecommendationRun::STATUS_COMPLETED, $run->getStatus());
    }

    public function testResumeResetsTransportFailuresToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordTransportFailure();
        $run->recordTransportFailure();
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertFalse($run->recordTransportFailure());
        self::assertFalse($run->recordTransportFailure());
        self::assertTrue($run->recordTransportFailure());
    }

    public function testRecordTransportFailureBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordTransportFailure();
    }

    public function testResumeIsOnlyLegalFromFailed(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));

        $run->resume();

        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
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

        self::assertSame(RecommendationRun::STATUS_COMPLETED, $run->getStatus());
        self::assertSame($when, $run->getCompletedAt());
        self::assertSame(3, $run->progress()->batchesDone);
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

        self::assertSame(RecommendationRun::STATUS_FAILED, $run->getStatus());
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

    public function testRecordInvalidReplyBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordInvalidReply('garbage');
    }

    private function makeRun(): RecommendationRun
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        return new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
    }
}
