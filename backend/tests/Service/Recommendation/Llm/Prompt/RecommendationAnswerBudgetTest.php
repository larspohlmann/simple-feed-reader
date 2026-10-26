<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt;

use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Model\OutputLimitModel;
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
            intdiv(max(1024, RecommendationAnswerBudget::PROFILE_ANSWER_TOKENS) * 150, 100),
            $this->answerBudget->answerBoundTokens(1, RecommendationResponseSchema::Distillation),
        );
    }

    public function testTheOutputBoundAddsTheReasoningHeadroomWithoutACap(): void
    {
        $allowed = new OutputLimitModel(Reasoning::Allowed, null);
        $suppressed = new OutputLimitModel(Reasoning::Suppressed, null);

        self::assertSame(10_500 + 32_000, $this->consolidationOutputBound($allowed));
        self::assertSame(10_500 + 8000, $this->consolidationOutputBound($suppressed));
    }

    public function testTheOutputBoundStaysUnderTheModelsCap(): void
    {
        self::assertSame(16_384, $this->consolidationOutputBound(new OutputLimitModel(Reasoning::Allowed, 16_384)));
    }

    public function testACapAboveTheOutputBoundLeavesItAlone(): void
    {
        self::assertSame(42_500, $this->consolidationOutputBound(new OutputLimitModel(Reasoning::Allowed, 42_501)));
    }

    private function consolidationOutputBound(OutputLimitModel $limit): int
    {
        return $this->answerBudget->outputBoundTokens(100, RecommendationResponseSchema::Consolidation, $limit);
    }
}
