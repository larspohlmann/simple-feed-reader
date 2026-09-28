<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Recommendation\Run\TickContext;
use App\Service\Recommendation\Run\TickDriver;
use App\Service\Recommendation\Settings\EffectiveRecommendationSettings;
use App\Service\Recommendation\Settings\RecommendationPackingSettings;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class TickContextTest extends TestCase
{
    private const string AT = '2026-08-07 09:00:00';

    public function testTheDriverDecidesTheRetryPlan(): void
    {
        self::assertTrue($this->tick($this->connection(), TickDriver::Worker)->retryPlan()->blocks());
        self::assertFalse($this->tick($this->connection(), TickDriver::Sweep)->retryPlan()->blocks());
    }

    public function testTheConnectionDecidesWhetherTheCallMayReason(): void
    {
        $connection = $this->connection();
        $connection->setSuppressReasoning(false);

        self::assertSame(Reasoning::Allowed, $this->tick($connection, TickDriver::Poll)->reasoning());
    }

    private function tick(AiProviderSettings $connection, TickDriver $driver): TickContext
    {
        return new TickContext(
            new RecommendationRun($connection->getUser(), new \DateTimeImmutable(self::AT)),
            $connection,
            new EffectiveRecommendationSettings(
                guidancePrompt: null,
                favoritesCap: 40,
                keptCap: 40,
                viewedCap: 80,
                candidatePoolSize: 500,
                lookbackDays: 2,
                picksLimit: 50,
                packing: new RecommendationPackingSettings(
                    contextWindow: 32768,
                    contextWindowSource: 'fallback',
                    batchSize: RecommendationBatchSize::Medium,
                    maximumBatchSize: RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
            ),
            $driver,
        );
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(
            new User('tick-context@example.test', new \DateTimeImmutable(self::AT)),
        );
    }
}
