<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** How large the candidate pool is, how far back it reaches, and how many picks a run keeps. */
#[ORM\Embeddable]
final readonly class RecommendationPoolLimits
{
    public function __construct(
        #[ORM\Column(options: ['default' => RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE])]
        public int $candidatePoolSize,
        /** How many days back the candidate pool reaches, counted as N x 24 h from the snapshot instant. */
        #[ORM\Column(options: ['default' => RecommendationSettings::DEFAULT_LOOKBACK_DAYS])]
        public int $lookbackDays,
        #[ORM\Column(options: ['default' => RecommendationSettings::DEFAULT_PICKS_LIMIT])]
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
