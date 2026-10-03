<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Enum\RecommendationBatchSize;
use App\Enum\RecommendationEngineKind;
use App\Http\RecommendationSettingsJson;
use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsJsonTest extends TestCase
{
    /**
     * The settings card shows the prompt the batch call sends: `fixedPrompt` must stay pointed at
     * `BATCH_SYSTEM_ROLE` and `BATCH_OUTPUT_CONTRACT`.
     */
    public function testFixedPromptIsTheBatchPromptTheRunnerActuallySends(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        /** @var array{role: string, outputContract: string} $fixedPrompt */
        $fixedPrompt = $state['fixedPrompt'];

        self::assertSame(RecommendationPromptText::BATCH_SYSTEM_ROLE, $fixedPrompt['role']);
        self::assertSame(RecommendationPromptText::BATCH_OUTPUT_CONTRACT, $fixedPrompt['outputContract']);

        // Content-level proof, independent of which constant the mapper reads:
        // phrasing unique to the live batch prompt is present, and phrasing
        // unique to the deleted rank-then-dedup prompt is gone.
        self::assertStringContainsString(
            'Return the id and the score only; do not write a reason.',
            $fixedPrompt['role'],
        );
        self::assertStringNotContainsString('four sections', $fixedPrompt['role']);
        self::assertStringNotContainsString('"reason"', $fixedPrompt['outputContract']);
    }

    /** Only the LLM's own prompt pieces depend on the prompt capability. */
    public function testAnEngineWithoutAPromptShowsNoPromptPieces(): void
    {
        $state = RecommendationSettingsJson::state(
            $this->effectiveSettings(),
            RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Jev),
            workerAlive: true,
        );

        self::assertArrayHasKey('defaultGuidancePrompt', $state);
        self::assertArrayHasKey('fixedPrompt', $state);
        self::assertNull($state['defaultGuidancePrompt']);
        self::assertNull($state['fixedPrompt']);
    }

    public function testStateEmitsShowScoreAndReasons(): void
    {
        $state = RecommendationSettingsJson::state(
            $this->effectiveSettings(showScoreAndReasons: true),
            self::llm(),
            workerAlive: true,
        );

        self::assertTrue($state['showScoreAndReasons']);
    }

    public function testStateEmitsShowScoreAndReasonsFalseByDefault(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        self::assertFalse($state['showScoreAndReasons']);
    }

    public function testStateEmitsFactoryDefaultsForTheExpertDraft(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        self::assertSame([
            'guidancePrompt' => null,
            'favoritesCap' => 40,
            'candidatePoolSize' => 500,
            'picksLimit' => 50,
            'batchSize' => 'medium',
            'contextWindow' => null,
        ], $state['expertDefaults']);
    }

    public function testStateEmitsTheEffectiveBatchSize(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        self::assertSame('medium', $state['batchSize']);
    }

    public function testStateEmitsTheExpertFieldBounds(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        self::assertSame([
            'favoritesCap' => ['min' => 0, 'max' => 500],
            'candidatePoolSize' => ['min' => 10, 'max' => 5000],
            'picksLimit' => ['min' => 1, 'max' => 500],
            'contextWindow' => ['min' => 4096, 'max' => 2097152],
        ], $state['expertBounds']);
    }

    /** The profile and its two history caps moved to /api/me/ai/profile. */
    public function testStateCarriesNoProfileKeys(): void
    {
        /** @var array{expertDefaults: array<string, mixed>, expertBounds: array<string, mixed>} $state */
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), self::llm(), workerAlive: true);

        foreach (['profileText', 'keptCap', 'viewedCap'] as $key) {
            self::assertArrayNotHasKey($key, $state);
            self::assertArrayNotHasKey($key, $state['expertDefaults']);
            self::assertArrayNotHasKey($key, $state['expertBounds']);
        }
    }

    private static function llm(): RecommendationEngineCapabilitiesModel
    {
        return RecommendationEngineCapabilitiesModel::of(RecommendationEngineKind::Llm);
    }

    private function effectiveSettings(bool $showScoreAndReasons = false): EffectiveRecommendationSettingsModel
    {
        return new EffectiveRecommendationSettingsModel(
            guidancePrompt: null,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: RecommendationPoolLimits::defaults(),
            packing: new RecommendationPackingSettingsModel(
                contextWindow: EffectiveRecommendationSettingsModel::FALLBACK_CONTEXT_WINDOW,
                contextWindowSource: 'fallback',
                batchSize: RecommendationBatchSize::Medium,
                maximumBatchSize: RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
            autoGenerateIntervalHours: null,
            showScoreAndReasons: $showScoreAndReasons,
        );
    }
}
