<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineKindTest extends TestCase
{
    public function testTheLlmDistillsThenScoresInBatchesThenConsolidates(): void
    {
        self::assertSame(
            [CallPhase::Distill, CallPhase::Batch, CallPhase::Consolidate],
            RecommendationEngineKind::Llm->phases(),
        );
    }

    /** The two single calls around the batches, which the progress counts like batches. */
    public function testTheLlmHasTwoSingleCallPhases(): void
    {
        self::assertSame(2, RecommendationEngineKind::Llm->singleCallPhaseCount());
    }

    public function testRunsAsksThePhasePlan(): void
    {
        self::assertTrue(RecommendationEngineKind::Llm->runs(CallPhase::Consolidate));
    }

    public function testJevDistilsThenAsksInBatches(): void
    {
        self::assertSame([CallPhase::Distill, CallPhase::Batch], RecommendationEngineKind::Jev->phases());
        self::assertSame(1, RecommendationEngineKind::Jev->singleCallPhaseCount());
        self::assertFalse(RecommendationEngineKind::Jev->runs(CallPhase::Consolidate));
    }
}
