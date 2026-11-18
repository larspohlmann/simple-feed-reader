<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\TrendingStoryHideRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;

/** Hides a Trending story for one reader, by its id, in both windows and through every rebuild that keeps it. */
final readonly class TrendingStoryHiding
{
    public function __construct(
        private TrendingStoryRepository $stories,
        private TrendingStoryHideRepository $hides,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @throws TrendingStoryNotFoundException */
    public function hide(int $userId, int $storyId): void
    {
        $this->stories->requireOwnedBy($userId, $storyId);
        $this->hides->hide($userId, $storyId, $this->clock->now());
    }
}
