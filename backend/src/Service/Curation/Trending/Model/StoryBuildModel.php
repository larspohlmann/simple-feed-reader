<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

/** One build of one window: the start its grouping read from, and the time new members join at. */
final readonly class StoryBuildModel
{
    public function __construct(
        public TrendingWindow $window,
        public \DateTimeImmutable $since,
        public \DateTimeImmutable $builtAt,
    ) {
    }
}
