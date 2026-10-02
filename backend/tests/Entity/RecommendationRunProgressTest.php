<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRunProgress;
use App\Enum\RecommendationEngineKind;
use PHPUnit\Framework\TestCase;

final class RecommendationRunProgressTest extends TestCase
{
    public function testConsolidationRunsEvenForASingleBatch(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan(
            [[1, 2, 3]],
            batchesDone: 1,
            attempts: 0,
            distilled: true,
            engineKind: RecommendationEngineKind::Llm,
        );

        self::assertTrue($progress->isConsolidationPhase);
    }

    public function testConsolidationWaitsUntilAllBatchesAreDone(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan(
            [[1], [2]],
            batchesDone: 1,
            attempts: 0,
            distilled: true,
            engineKind: RecommendationEngineKind::Llm,
        );

        self::assertFalse($progress->isConsolidationPhase);
    }

    public function testConsolidationNeverStartsWithoutAPlanEvenIfDistilled(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan(
            null,
            batchesDone: 0,
            attempts: 0,
            distilled: true,
            engineKind: RecommendationEngineKind::Llm,
        );

        self::assertFalse($progress->isConsolidationPhase);
    }

    public function testDistillPendingUntilDistilled(): void
    {
        self::assertTrue(
            RecommendationRunProgress::forBatchPlan(
                [[1]],
                0,
                0,
                distilled: false,
                engineKind: RecommendationEngineKind::Llm,
            )->distillPending,
        );
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan(
                [[1]],
                0,
                0,
                distilled: true,
                engineKind: RecommendationEngineKind::Llm,
            )->distillPending,
        );
    }

    public function testTotalCountsDistillationAndConsolidation(): void
    {
        $progress = RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, true, RecommendationEngineKind::Llm);

        self::assertSame(4, $progress->batchesTotal);
    }
}
