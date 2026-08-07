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
        self::assertFalse($progress->needsMerge);
        self::assertFalse($progress->isMergePhase);
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
        self::assertTrue($run->progress()->needsMerge);
    }

    public function testASingleBatchNeedsNoMerge(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2, 3]]);

        self::assertSame(1, $run->progress()->batchesTotal);
        self::assertFalse($run->progress()->needsMerge);
    }

    public function testRecordingWinnersAdvancesAndClearsRetryState(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1, 2], [3]]);
        $run->recordInvalidReply('garbage');

        $run->recordBatchWinners([['id' => 2, 'reason' => 'fresh']]);

        self::assertSame(1, $run->progress()->batchesDone);
        self::assertSame([[['id' => 2, 'reason' => 'fresh']]], $run->getWinners());
        self::assertNull($run->getLastInvalidReply());
        self::assertFalse($run->attemptsExhausted());
        self::assertSame(1, $run->progress()->nextBatchIndex);
        self::assertFalse($run->progress()->isMergePhase);
    }

    public function testThirdInvalidReplyExhaustsAttempts(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1]]);
        $run->recordInvalidReply('a');
        $run->recordInvalidReply('b');
        self::assertFalse($run->attemptsExhausted());

        $run->recordInvalidReply('c');

        self::assertTrue($run->attemptsExhausted());
        self::assertSame('c', $run->getLastInvalidReply());
    }

    public function testMergePhaseAfterAllBatchCalls(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordBatchWinners([['id' => 1, 'reason' => 'r']]);
        $run->recordBatchWinners([['id' => 2, 'reason' => 'r']]);

        self::assertTrue($run->progress()->isMergePhase);
    }

    public function testAllBatchCallsDoneIsFalseUntilEveryBatchReportedWinners(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);

        self::assertFalse($run->progress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 1, 'reason' => 'r']]);
        self::assertFalse($run->progress()->allBatchCallsDone);

        $run->recordBatchWinners([['id' => 2, 'reason' => 'r']]);
        self::assertTrue($run->progress()->allBatchCallsDone);
    }

    public function testRecordBatchWinnersResetsAttemptsToExactlyZero(): void
    {
        $run = $this->makeRun();
        $run->snapshot([[1], [2]]);
        $run->recordInvalidReply('a');
        $run->recordInvalidReply('b');

        $run->recordBatchWinners([['id' => 1, 'reason' => 'r']]);

        // Exactly MAX_ATTEMPTS (3) fresh invalid replies are needed to exhaust
        // again — pins the reset at 0, not -1 or 1.
        $run->recordInvalidReply('c');
        $run->recordInvalidReply('d');
        self::assertFalse($run->attemptsExhausted());
        $run->recordInvalidReply('e');
        self::assertTrue($run->attemptsExhausted());
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
        self::assertFalse($run->attemptsExhausted());
        $run->recordInvalidReply('e');
        self::assertTrue($run->attemptsExhausted());
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

    public function testFailBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->fail('boom', new \DateTimeImmutable('2026-08-07T10:00:00Z'));
    }

    public function testRecordBatchWinnersBeforeSnapshotThrows(): void
    {
        $run = $this->makeRun();

        $this->expectException(\LogicException::class);
        $run->recordBatchWinners([['id' => 1, 'reason' => 'r']]);
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
