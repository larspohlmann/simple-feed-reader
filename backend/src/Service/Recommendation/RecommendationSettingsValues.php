<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The stored shape of a per-user recommendation settings row: every field is
 * an override, so `null` (or, for the caps, an absent row) means "use the
 * default" rather than "off". EffectiveRecommendationSettings is what a
 * caller actually reads.
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public int $keptCap,
        public int $viewedCap,
        public int $candidatePoolSize,
        public int $picksLimit,
        public ?int $contextWindow,
        public bool $debugEnabled,
    ) {
    }
}
