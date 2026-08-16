<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use App\Service\Recommendation\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\RecommendationPromptBuilder;
use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationCompletionRequestFactoryTest extends TestCase
{
    private RecommendationCompletionRequestFactory $factory;
    private RecommendationPromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        $this->promptBuilder = new RecommendationPromptBuilder();
        $this->factory = new RecommendationCompletionRequestFactory($this->promptBuilder);
    }

    /**
     * The reasoning headroom is what a thinking phase spends before the answer
     * begins. A connection that suppresses reasoning has no thinking phase, so
     * paying for one only licenses a looping model to generate for an hour
     * before `max_tokens` stops it (#437).
     */
    public function testAConnectionThatSuppressesReasoningPaysNoReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: true),
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Ranking,
        );

        self::assertSame($this->promptBuilder->answerTokenReserve(45), $request->maxAnswerTokens);
    }

    /**
     * The other half of the same decision: a connection that may reason still
     * needs room to think before it answers, or its answer is truncated
     * (#327).
     */
    public function testAConnectionThatMayReasonKeepsItsReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: false),
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Ranking,
        );

        self::assertSame($this->promptBuilder->outputTokenReserve(45), $request->maxAnswerTokens);
    }

    private function settings(bool $suppressReasoning): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('reader@example.test', new \DateTimeImmutable('2026-08-16 09:00:00')),
            'shiva.local',
            'http://shiva.local:1234/v1',
            new SealedApiKey('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'cdef',
            new \DateTimeImmutable('2026-08-16 09:30:00'),
        );
        $settings->chooseModel('qwen/qwen3-4b-2507', new \DateTimeImmutable('2026-08-16 09:30:00'), null);
        $settings->setSuppressReasoning($suppressReasoning);

        return $settings;
    }
}
