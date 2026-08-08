<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The recommendation settings a caller actually reads: every override from
 * RecommendationSettingsValues resolved against its default, with the
 * context window additionally resolved against the account's AI provider.
 * RecommendationSettingsResolver is the only producer.
 */
final readonly class EffectiveRecommendationSettings
{
    public const int DEFAULT_FAVORITES_CAP = 40;
    public const int DEFAULT_KEPT_CAP = 40;
    public const int DEFAULT_VIEWED_CAP = 80;
    public const int DEFAULT_CANDIDATE_POOL_SIZE = 500;
    public const int DEFAULT_PICKS_LIMIT = 50;
    public const int FALLBACK_CONTEXT_WINDOW = 32768;

    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public int $keptCap,
        public int $viewedCap,
        public int $candidatePoolSize,
        public int $picksLimit,
        public int $contextWindow,
        public string $contextWindowSource,
        public bool $debugEnabled,
    ) {
    }
}
