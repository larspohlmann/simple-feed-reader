<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;

/**
 * The stored recommendation settings row: every field is an override, so null (or no row) means "use the default".
 * The profile and its own settings travel apart, in StoredProfile and ProfileSettingsValues.
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public RecommendationPoolLimits $poolLimits,
        public ?int $contextWindow,
        public RecommendationBatchSize $batchSize,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        public bool $showScoreAndReasons = false,
    ) {
    }
}
