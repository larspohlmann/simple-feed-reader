<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationCapabilitiesJsons;
use PHPUnit\Framework\TestCase;

final class RecommendationCapabilitiesJsonTest extends TestCase
{
    public function testItNamesTheEnginesTuningFieldsByTheirWireNamesInTheEnginesOrder(): void
    {
        $json = RecommendationCapabilitiesJsons::reporting(new RecommendationEngineCapabilitiesModel(
            false,
            [RecommendationTuningField::SlowModel, RecommendationTuningField::ContextWindow],
        ));

        self::assertSame(
            ['reasons' => false, 'tuningFields' => ['slowModel', 'contextWindow']],
            $json->of($this->connection()),
        );
    }

    public function testAnEngineThatWritesReasonsButReadsNoTuningSaysSo(): void
    {
        $json = RecommendationCapabilitiesJsons::reporting(new RecommendationEngineCapabilitiesModel(true, []));

        self::assertSame(['reasons' => true, 'tuningFields' => []], $json->of($this->connection()));
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('capabilities-json@example.test', new \DateTimeImmutable('2026-10-02 09:00:00')),
        );
    }
}
