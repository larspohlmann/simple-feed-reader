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
}
