<?php

declare(strict_types=1);

namespace App\Entity;

/** How large the candidate pool is, how far back it reaches, and how many picks a run keeps. */
final readonly class RecommendationPoolLimits
{
    public function __construct(
        public int $candidatePoolSize,
        /** How many days back the candidate pool reaches, counted as N x 24 h from the snapshot instant. */
        public int $lookbackDays,
        public int $picksLimit,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            RecommendationSettings::DEFAULT_PICKS_LIMIT,
        );
    }
}
