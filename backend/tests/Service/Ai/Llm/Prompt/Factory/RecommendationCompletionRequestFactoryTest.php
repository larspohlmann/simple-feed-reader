<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Llm\Prompt\Factory;

use App\Entity\AiProviderSettings;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Service\Ai\Llm\Completion\Model\Reasoning;
use App\Service\Ai\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Ai\Llm\Prompt\Model\CallPromptModel;
use App\Service\Ai\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Ai\Llm\Prompt\RecommendationAnswerBudget;
use PHPUnit\Framework\TestCase;

final class RecommendationCompletionRequestFactoryTest extends TestCase
{
    private RecommendationCompletionRequestFactory $factory;
    private RecommendationAnswerBudget $answerBudget;

    protected function setUp(): void
    {
        $this->answerBudget = new RecommendationAnswerBudget();
        $this->factory = new RecommendationCompletionRequestFactory($this->answerBudget);
    }

    public function testASuppressedConnectionKeepsAReducedReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: true),
            $this->prompt(),
        );

        self::assertSame(
            $this->answerBudget->outputBoundTokens(
                45,
                RecommendationResponseSchema::Consolidation,
                reasoning: Reasoning::Suppressed,
            ),
            $request->maxAnswerTokens,
        );
    }

    public function testAConnectionThatMayReasonKeepsTheFullReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: false),
            $this->prompt(),
        );

        $full = $this->answerBudget->outputBoundTokens(
            45,
            RecommendationResponseSchema::Consolidation,
            reasoning: Reasoning::Allowed,
        );
        self::assertSame($full, $request->maxAnswerTokens);
        self::assertGreaterThan(
            $this->answerBudget->outputBoundTokens(
                45,
                RecommendationResponseSchema::Consolidation,
                reasoning: Reasoning::Suppressed,
            ),
            $full,
        );
    }

    private function prompt(): CallPromptModel
    {
        return new CallPromptModel(
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Consolidation,
        );
    }

    private function settings(bool $suppressReasoning): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('reader@example.test', new \DateTimeImmutable('2026-08-16 09:00:00')),
            'shiva.local',
            'http://shiva.local:1234/v1',
            new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'cdef',
            new \DateTimeImmutable('2026-08-16 09:30:00'),
        );
        $settings->chooseModel('qwen/qwen3-4b-2507', new \DateTimeImmutable('2026-08-16 09:30:00'), null);
        $settings->setSuppressReasoning($suppressReasoning);

        return $settings;
    }
}
