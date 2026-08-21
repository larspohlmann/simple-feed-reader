<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RecommendationSettingsJson;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPackingSettings;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsJsonTest extends TestCase
{
    public function testStateEmitsProfileText(): void
    {
        $effective = $this->effectiveSettings(profileText: 'Likes Rust and homelab posts.');

        $state = RecommendationSettingsJson::state($effective, workerAlive: true);

        self::assertSame('Likes Rust and homelab posts.', $state['profileText']);
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
