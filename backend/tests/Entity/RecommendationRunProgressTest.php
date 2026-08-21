<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRunProgress;
use PHPUnit\Framework\TestCase;

final class RecommendationRunProgressTest extends TestCase
{
    public function testConsolidationRunsEvenForASingleBatch(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan([[1, 2, 3]], batchesDone: 1, attempts: 0, distilled: true);

        self::assertTrue($progress->isConsolidationPhase);
    }

    public function testConsolidationWaitsUntilAllBatchesAreDone(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan([[1], [2]], batchesDone: 1, attempts: 0, distilled: true);

        self::assertFalse($progress->isConsolidationPhase);
    }

    public function testConsolidationNeverStartsWithoutAPlanEvenIfDistilled(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan(null, batchesDone: 0, attempts: 0, distilled: true);

        self::assertFalse($progress->isConsolidationPhase);
    }

    public function testDistillPendingUntilDistilled(): void
    {
        self::assertTrue(
            RecommendationRunProgress::forBatchPlan([[1]], 0, 0, distilled: false)->distillPending,
        );
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan([[1]], 0, 0, distilled: true)->distillPending,
        );
    }

    public function testTotalCountsDistillationAndConsolidation(): void
    {
        self::assertSame(4, RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, true)->batchesTotal);
    }
}
