<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
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

    public function testAJevConnectionReportsNoReasonsNoPromptAndOnlyTheBatchConcurrency(): void
    {
        $connection = AiProviderSettingsFactory::build(
            new User('capabilities-json-jev@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
        $connection->chooseModel('jev-latest', new \DateTimeImmutable('2026-10-02 09:05:00'), 64_000);

        self::assertSame(
            RecommendationCapabilitiesJsons::JEV,
            RecommendationCapabilitiesJsons::ofTheKind()->of($connection),
        );
    }
}
