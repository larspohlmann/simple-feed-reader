<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
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

    public function testTheCallRouteIsTheTicksConnectionUnderItsDriversRetryPlan(): void
    {
        $connection = $this->connection();
        $tick = $this->tick($connection, TickDriver::Worker);

        $route = $tick->callRoute();

        self::assertSame($connection, $route->connection);
        self::assertEquals(RetryPlanModel::blocking(), $route->retryPlan);
    }

    public function testAScoringConnectionsTickNamesItsProtocol(): void
    {
        $connection = $this->connection();
        $connection->chooseModel(
            new ModelDescriptor('acme/decider-2', 16_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable(self::AT),
        );

        self::assertSame(
            ScoringProtocol::SystemOne,
            $this->tick($connection, TickDriver::Worker)->requireScoringProtocol(),
        );
    }

    public function testAnLlmConnectionsTickHasNoProtocolToRequire(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('This tick\'s connection speaks no scoring protocol.');

        $this->tick($this->connection(), TickDriver::Worker)->requireScoringProtocol();
    }

    private function tick(AiProviderSettings $connection, TickDriver $driver): TickContext
    {
        return new TickContext(
            new RecommendationRun($connection->getUser(), new \DateTimeImmutable(self::AT)),
            $connection,
            RecommendationEngineKind::Llm,
            new EffectiveRecommendationSettingsModel(
                guidancePrompt: null,
                historyCaps: RecommendationHistoryCaps::defaults(),
                poolLimits: RecommendationPoolLimits::defaults(),
                packing: new RecommendationPackingSettingsModel(
                    contextWindow: 32768,
                    contextWindowSource: 'fallback',
                    batchSize: RecommendationBatchSize::Medium,
                    maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
                autoGenerateIntervalHours: null,
                showScoreAndReasons: false,
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
