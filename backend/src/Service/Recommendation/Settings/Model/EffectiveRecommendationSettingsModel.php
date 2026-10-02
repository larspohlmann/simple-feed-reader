<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Settings\Model;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;

/**
 * The settings every recommendation service reads: each override resolved against its default, the context window
 * against the account's AI provider too. RecommendationSettingsResolver is the only producer.
 */
final readonly class EffectiveRecommendationSettingsModel
{
    public const int FALLBACK_CONTEXT_WINDOW = 32768;

    public function __construct(
        public ?string $guidancePrompt,
        public RecommendationHistoryCaps $historyCaps,
        public RecommendationPoolLimits $poolLimits,
        public RecommendationPackingSettingsModel $packing,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours,
        public ?string $profileText,
        public bool $showScoreAndReasons,
    ) {
    }
}
