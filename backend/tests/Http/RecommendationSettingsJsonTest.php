<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Enum\RecommendationBatchSize;
use App\Http\RecommendationSettingsJson;
use App\Service\Recommendation\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsJsonTest extends TestCase
{
    public function testStateEmitsProfileText(): void
    {
        $effective = $this->effectiveSettings(profileText: 'Likes Rust and homelab posts.');

        $state = RecommendationSettingsJson::state($effective, workerAlive: true);

        self::assertSame('Likes Rust and homelab posts.', $state['profileText']);
    }

    /**
     * The settings card shows the prompt the batch call sends: `fixedPrompt` must stay pointed at
     * `BATCH_SYSTEM_ROLE` and `BATCH_OUTPUT_CONTRACT`.
     */
    public function testFixedPromptIsTheBatchPromptTheRunnerActuallySends(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

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

    public function testStateEmitsNullProfileTextWhenAbsent(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

        self::assertNull($state['profileText']);
    }

    public function testStateEmitsShowReasons(): void
    {
        $state = RecommendationSettingsJson::state(
            $this->effectiveSettings(showReasons: true),
            workerAlive: true,
        );

        self::assertTrue($state['showReasons']);
    }

    public function testStateEmitsShowReasonsFalseByDefault(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

        self::assertFalse($state['showReasons']);
    }

    public function testStateEmitsFactoryDefaultsForTheExpertDraft(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

        self::assertSame([
            'guidancePrompt' => null,
            'favoritesCap' => 40,
            'keptCap' => 40,
            'viewedCap' => 80,
            'candidatePoolSize' => 500,
            'picksLimit' => 50,
            'batchSize' => 'medium',
            'contextWindow' => null,
        ], $state['expertDefaults']);
    }

    public function testStateEmitsTheEffectiveBatchSize(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

        self::assertSame('medium', $state['batchSize']);
    }

    public function testStateEmitsTheExpertFieldBounds(): void
    {
        $state = RecommendationSettingsJson::state($this->effectiveSettings(), workerAlive: true);

        self::assertSame([
            'favoritesCap' => ['min' => 0, 'max' => 500],
            'keptCap' => ['min' => 0, 'max' => 500],
            'viewedCap' => ['min' => 0, 'max' => 500],
            'candidatePoolSize' => ['min' => 10, 'max' => 5000],
            'picksLimit' => ['min' => 1, 'max' => 500],
            'contextWindow' => ['min' => 4096, 'max' => 2097152],
        ], $state['expertBounds']);
    }

    private function effectiveSettings(
        ?string $profileText = null,
        bool $showReasons = false,
    ): EffectiveRecommendationSettingsModel {
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
            profileText: $profileText,
            showReasons: $showReasons,
        );
    }
}
