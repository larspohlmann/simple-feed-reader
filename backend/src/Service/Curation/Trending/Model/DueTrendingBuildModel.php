<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

final readonly class DueTrendingBuildModel
{
    public function __construct(
        public int $userId,
        public TrendingWindow $window,
        public TrendingLastBuildsModel $lastBuilds,
    ) {
    }

    /** Never-built pairs come before any built one. */
    public function lastBuiltTimestamp(): int
    {
        return $this->lastBuilds->builtAt($this->window)?->getTimestamp() ?? PHP_INT_MIN;
    }
}
