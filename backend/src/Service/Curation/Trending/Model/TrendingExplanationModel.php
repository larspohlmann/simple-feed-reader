<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

final readonly class TrendingExplanationModel
{
    /**
     * @param list<TrendingStoryModel> $groups widest first, then newest
     * @param list<NearMissModel>      $nearMisses
     */
    public function __construct(
        public TrendingWindow $window,
        public int $entryCount,
        public array $groups,
        public array $nearMisses,
        public int $durationMilliseconds,
        public int $peakMemoryBytes,
    ) {
    }
}
