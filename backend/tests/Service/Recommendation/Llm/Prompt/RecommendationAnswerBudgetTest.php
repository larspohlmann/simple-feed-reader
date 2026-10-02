<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt;

use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget;
use PHPUnit\Framework\TestCase;

final class RecommendationAnswerBudgetTest extends TestCase
{
    private RecommendationAnswerBudget $answerBudget;

    protected function setUp(): void
    {
        $this->answerBudget = new RecommendationAnswerBudget();
    }

    public function testAnswerBoundIsSchemaAware(): void
    {
        self::assertSame(
            intdiv(max(1024, 100 * 15) * 150, 100),
            $this->answerBudget->answerBoundTokens(100, RecommendationResponseSchema::BatchScore),
        );
        self::assertSame(
            intdiv(max(1024, 100 * 70) * 150, 100),
            $this->answerBudget->answerBoundTokens(100, RecommendationResponseSchema::Consolidation),
        );
        self::assertSame(
            intdiv(max(1024, 1200) * 150, 100),
            $this->answerBudget->answerBoundTokens(1, RecommendationResponseSchema::Distillation),
        );
    }
}
