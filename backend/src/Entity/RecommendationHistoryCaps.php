<?php

declare(strict_types=1);

namespace App\Entity;

/** How many of the reader's favorite, shared, kept and viewed posts a recommendation prompt carries. */
final readonly class RecommendationHistoryCaps
{
    public const int SHARED_CAP = 40;

    public function __construct(
        public int $favorites,
        public int $kept,
        public int $viewed,
        public int $shared = self::SHARED_CAP,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            RecommendationSettings::DEFAULT_FAVORITES_CAP,
            ProfileTuning::DEFAULT_KEPT_CAP,
            ProfileTuning::DEFAULT_VIEWED_CAP,
        );
    }
}
