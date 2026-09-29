<?php

declare(strict_types=1);

namespace App\Entity;

/** How many of the reader's favorite, kept and viewed posts a recommendation prompt carries. */
final readonly class RecommendationHistoryCaps
{
    public function __construct(
        public int $favorites,
        public int $kept,
        public int $viewed,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            RecommendationSettings::DEFAULT_FAVORITES_CAP,
            RecommendationSettings::DEFAULT_KEPT_CAP,
            RecommendationSettings::DEFAULT_VIEWED_CAP,
        );
    }
}
