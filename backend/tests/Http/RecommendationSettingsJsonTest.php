<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RecommendationSettingsJson;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPackingSettings;
use App\Service\Recommendation\RecommendationPromptText;
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
     * The settings card must show the prompt the batch call actually sends,
     * not the superseded rank-then-dedup prompt (#493 Task 13, Ruling F).
     * `RecommendationPromptText::SYSTEM_ROLE`/`OUTPUT_CONTRACT` no longer
     * exist, so this pins the emitted strings directly to
     * `BATCH_SYSTEM_ROLE`/`BATCH_OUTPUT_CONTRACT` -- an assertion that fails
     * outright if `fixedPrompt` is ever repointed away from the live batch
     * prompt again.
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

    private function effectiveSettings(?string $profileText = null): EffectiveRecommendationSettings
    {
        return new EffectiveRecommendationSettings(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            packing: new RecommendationPackingSettings(
                contextWindow: EffectiveRecommendationSettings::FALLBACK_CONTEXT_WINDOW,
                contextWindowSource: 'fallback',
                batchCount: null,
                maximumBatchSize: RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
            ),
            debugEnabled: false,
            profileText: $profileText,
        );
    }
}
