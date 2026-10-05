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
        self::assertTrue(
            RecommendationRunProgress::forBatchPlan([[1, 2, 3]], 1, 0, RecommendationEngineKind::Llm)
                ->isConsolidationPhase,
        );
    }

    public function testConsolidationWaitsUntilAllBatchesAreDone(): void
    {
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan([[1], [2]], 1, 0, RecommendationEngineKind::Llm)
                ->isConsolidationPhase,
        );
    }

    public function testConsolidationNeverStartsWithoutAPlan(): void
    {
        self::assertFalse(
            RecommendationRunProgress::forBatchPlan(null, 0, 0, RecommendationEngineKind::Llm)->isConsolidationPhase,
        );
    }

    public function testTheLlmTotalCountsTheBatchesAndTheConsolidation(): void
    {
        self::assertSame(
            3,
            RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, RecommendationEngineKind::Llm)->batchesTotal,
        );
    }

    public function testThePlanCountsItsBatchesAloneAndARunWithoutOneCountsNone(): void
    {
        $planned = RecommendationRunProgress::forBatchPlan([[1], [2]], 0, 0, RecommendationEngineKind::Llm);
        $unplanned = RecommendationRunProgress::forBatchPlan(null, 0, 0, RecommendationEngineKind::Llm);

        self::assertSame(2, $planned->batchCount);
        self::assertNull($unplanned->batchCount);
    }

    public function testAScoringPlanCountsOnlyItsBatchesAndHasNoConsolidation(): void
    {
        $pending = RecommendationRunProgress::forBatchPlan([[1], [2], [3]], 0, 0, RecommendationEngineKind::Scoring);
        $done = RecommendationRunProgress::forBatchPlan([[1], [2], [3]], 3, 0, RecommendationEngineKind::Scoring);

        self::assertSame(3, $pending->batchesTotal);
        self::assertTrue($done->allBatchCallsDone);
        self::assertFalse($done->isConsolidationPhase);
    }
}
