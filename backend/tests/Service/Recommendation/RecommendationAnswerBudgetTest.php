<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationAnswerBudget;
use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationAnswerBudgetTest extends TestCase
{
    /**
     * A score-only batch reply is `{"id":123,"score":843}` — no `reason` — so
     * it is charged a fifth of the reason-bearing pick rate. Distillation
     * answers one profile string, so it is charged a fixed reserve regardless
     * of how many items informed it. Consolidation still writes a `reason` per
     * pick, so it keeps the full pick rate (#493).
     */
    public function testAnswerBoundIsSchemaAware(): void
    {
        self::assertSame(
            intdiv(max(1024, 100 * 15) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::BatchScore),
        );
        self::assertSame(
            intdiv(max(1024, 100 * 70) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(100, RecommendationResponseSchema::Consolidation),
        );
        self::assertSame(
            intdiv(max(1024, 1200) * 150, 100),
            RecommendationAnswerBudget::answerBoundTokens(1, RecommendationResponseSchema::Distillation),
        );
    }
}
