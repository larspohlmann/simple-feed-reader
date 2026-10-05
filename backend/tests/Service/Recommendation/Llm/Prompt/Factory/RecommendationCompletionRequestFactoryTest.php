<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Prompt\Factory;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\SealedSecret;
use App\Entity\User;
use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Factory\RecommendationCompletionRequestFactory;
use App\Service\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\RecommendationAnswerBudget;
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

        self::assertSame($this->headroomFor(Reasoning::Suppressed), $request->maxAnswerTokens);
    }

    public function testAConnectionThatMayReasonKeepsTheFullReasoningHeadroom(): void
    {
        $request = $this->factory->create(
            $this->settings(suppressReasoning: false),
            $this->prompt(),
        );

        $full = $this->headroomFor(Reasoning::Allowed);
        self::assertSame($full, $request->maxAnswerTokens);
        self::assertGreaterThan($this->headroomFor(Reasoning::Suppressed), $full);
    }

    public function testAModelThatRefusedSuppressionIsAskedWithTheFullReasoningHeadroom(): void
    {
        $connection = $this->settings(suppressReasoning: true);
        $connection->recordSuppressionRefused();

        $request = $this->factory->create($connection, $this->prompt());

        self::assertSame(Reasoning::Allowed, $request->reasoning);
        self::assertSame($this->headroomFor(Reasoning::Allowed), $request->maxAnswerTokens);
    }

    private function headroomFor(Reasoning $reasoning): int
    {
        return $this->answerBudget->outputBoundTokens(45, RecommendationResponseSchema::Consolidation, $reasoning);
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
        $settings->chooseModel(
            new ModelDescriptor('qwen/qwen3-4b-2507', null),
            new \DateTimeImmutable('2026-08-16 09:30:00'),
        );
        $settings->setSuppressReasoning($suppressReasoning);

        return $settings;
    }
}
