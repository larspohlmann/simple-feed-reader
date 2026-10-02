<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Jev\Pass\JevWave;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

final class JevWaveTest extends TestCase
{
    private const string AT = '2026-10-02 09:00:00';

    public function testTheWaveAsksTheConnectionsModel(): void
    {
        $connection = $this->connection();
        $connection->chooseModel('jev-preview', new \DateTimeImmutable(self::AT), 64_000);

        self::assertSame('jev-preview', $this->wave($connection)->model());
    }

    public function testAConnectionWithoutAModelHasNoWaveToSend(): void
    {
        $this->expectException(\LogicException::class);

        $this->wave($this->connection())->model();
    }

    private function wave(AiProviderSettings $connection): JevWave
    {
        $tick = new TickContext(
            new RecommendationRun($connection->getUser(), new \DateTimeImmutable(self::AT)),
            $connection,
            RecommendationEngineKind::Jev,
            new EffectiveRecommendationSettingsModel(
                guidancePrompt: null,
                historyCaps: RecommendationHistoryCaps::defaults(),
                poolLimits: RecommendationPoolLimits::defaults(),
                packing: new RecommendationPackingSettingsModel(
                    contextWindow: 64_000,
                    contextWindowSource: 'fallback',
                    batchSize: RecommendationBatchSize::Medium,
                    maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
                ),
                debugEnabled: false,
                autoGenerateIntervalHours: null,
                profileText: null,
                showScoreAndReasons: false,
            ),
            TickDriver::Worker,
        );

        return new JevWave($tick, [], []);
    }

    private function connection(): AiProviderSettings
    {
        return AiProviderSettingsFactory::build(new User('jev-wave@example.test', new \DateTimeImmutable(self::AT)));
    }
}
