<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationCapabilitiesJsons;
use PHPUnit\Framework\TestCase;

final class RecommendationCapabilitiesJsonTest extends TestCase
{
    public function testItNamesTheKindsTuningFieldsByTheirWireNamesInTheKindsOrder(): void
    {
        $connection = AiProviderSettingsFactory::build(
            new User('capabilities-json@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );

        self::assertSame(
            RecommendationCapabilitiesJsons::LLM,
            RecommendationCapabilitiesJsons::ofTheKind()->of($connection),
        );
    }

    public function testAScoringConnectionReportsNoReasonsNoPromptAndOnlyTheBatchConcurrency(): void
    {
        $connection = AiProviderSettingsFactory::build(
            new User('capabilities-json-scoring@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-02 09:05:00'),
        );

        self::assertSame(
            RecommendationCapabilitiesJsons::SCORING,
            RecommendationCapabilitiesJsons::ofTheKind()->of($connection),
        );
    }

    public function testTheScoringKindReportsTheCapabilitiesAScoringConnectionHas(): void
    {
        self::assertSame(
            RecommendationCapabilitiesJsons::SCORING,
            RecommendationCapabilitiesJsons::ofTheKind()->ofKind(RecommendationEngineKind::Scoring),
        );
    }

    public function testTheLlmKindReportsTheLlmCapabilities(): void
    {
        self::assertSame(
            RecommendationCapabilitiesJsons::LLM,
            RecommendationCapabilitiesJsons::ofTheKind()->ofKind(RecommendationEngineKind::Llm),
        );
    }
}
