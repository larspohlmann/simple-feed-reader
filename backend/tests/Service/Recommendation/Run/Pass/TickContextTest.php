<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\RecommendationEngineKind;
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

    /** The profile connection answers for the tick while a borrowed distillation is pending, the tick's own after. */
    public function testTheConnectionInFlightIsTheProfileConnectionUntilTheProfileIsRecorded(): void
    {
        $jev = $this->connection();
        $profile = AiProviderSettingsFactory::build($jev->getUser(), 'Profile', 'https://profile.example.test/v1');
        $tick = $this->tick($jev, TickDriver::Worker);
        $tick->run->snapshot(RecommendationEngineKind::Jev, [[1]]);
        $borrowing = $tick->borrowingProfileFrom($profile, $tick->settings);

        self::assertSame($profile, $borrowing->connectionInFlight());
        $tick->run->recordProfile('Likes Rust.');
        self::assertSame($jev, $borrowing->connectionInFlight());
    }

    public function testTheProfileTickRunsTheProfileConnectionAsAnLlmWithItsOwnSettings(): void
    {
        $jev = $this->connection();
        $profile = AiProviderSettingsFactory::build($jev->getUser(), 'Profile', 'https://profile.example.test/v1');
        $tick = $this->tick($jev, TickDriver::Sweep);
        $profileSettings = $this->tick($profile, TickDriver::Sweep)->settings;

        $profileTick = $tick->borrowingProfileFrom($profile, $profileSettings)->profileTick();

        self::assertNotNull($profileTick);
        self::assertSame($tick->run, $profileTick->run);
        self::assertSame($profile, $profileTick->connection);
        self::assertSame(RecommendationEngineKind::Llm, $profileTick->engineKind);
        self::assertSame($profileSettings, $profileTick->settings);
        self::assertSame(TickDriver::Sweep, $profileTick->driver);
        self::assertNull($profileTick->borrowedProfile);
    }

    public function testATickThatBorrowsNothingHasNoProfileTick(): void
    {
        self::assertNull($this->tick($this->connection(), TickDriver::Worker)->profileTick());
    }

    public function testATickWithoutAProfileTickCallsItsOwnConnection(): void
    {
        $connection = $this->connection();
        $tick = $this->tick($connection, TickDriver::Worker);
        $tick->run->snapshot(RecommendationEngineKind::Llm, [[1]]);

        self::assertSame($connection, $tick->connectionInFlight());
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
                profileText: null,
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
