<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use App\Service\Recommendation\RecommendationAnswerBudget;
use App\Service\Recommendation\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\RecommendationResponseSchema;
use PHPUnit\Framework\TestCase;

final class RecommendationCompletionRequestFactoryTest extends TestCase
{
    private RecommendationCompletionRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RecommendationCompletionRequestFactory();
    }

    /**
     * A suppressed connection still keeps the reasoning headroom in max_tokens.
     * suppressReasoning sends `reasoning: {effort: none}` as a hint, but a local
     * reasoning model routinely thinks anyway; with no headroom its answer was
     * guillotined at finish_reason: length once batches grew past ~45 items
     * (#493 follow-up). The headroom is a ceiling, not a reservation: a model
     * that honours the hint stops early and pays nothing for the unused room.
     */
    public function testASuppressedConnectionStillKeepsReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: true),
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Consolidation,
        );

        self::assertSame(
            RecommendationAnswerBudget::outputBoundTokens(45, RecommendationResponseSchema::Consolidation),
            $request->maxAnswerTokens,
        );
    }

    /**
     * A connection that may reason gets the same budget: the reasoning headroom
     * is now independent of the suppress hint, so both paths need room to think
     * before answering or the answer is truncated (#327, #493).
     */
    public function testAConnectionThatMayReasonKeepsItsReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: false),
            [['role' => 'user', 'content' => 'rank these']],
            45,
            RecommendationResponseSchema::Consolidation,
        );

        self::assertSame(
            RecommendationAnswerBudget::outputBoundTokens(45, RecommendationResponseSchema::Consolidation),
            $request->maxAnswerTokens,
        );
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
