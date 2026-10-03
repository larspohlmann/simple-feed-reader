<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineKindTest extends TestCase
{
    public function testTheLlmScoresInBatchesThenConsolidates(): void
    {
        self::assertSame([CallPhase::Batch, CallPhase::Consolidate], RecommendationEngineKind::Llm->phases());
        self::assertSame(1, RecommendationEngineKind::Llm->singleCallPhaseCount());
    }

    public function testRunsAsksThePhasePlan(): void
    {
        self::assertTrue(RecommendationEngineKind::Llm->runs(CallPhase::Consolidate));
        self::assertFalse(RecommendationEngineKind::Llm->runs(CallPhase::Distill));
    }

    public function testJevOnlyAsksInBatches(): void
    {
        self::assertSame([CallPhase::Batch], RecommendationEngineKind::Jev->phases());
        self::assertSame(0, RecommendationEngineKind::Jev->singleCallPhaseCount());
        self::assertFalse(RecommendationEngineKind::Jev->runs(CallPhase::Consolidate));
    }
}
