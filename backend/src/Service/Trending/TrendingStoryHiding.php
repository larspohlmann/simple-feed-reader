<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingHiddenEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Exception\TrendingStoryNotFoundException;

/** Hides a Trending story for one reader, by its entries, so a rebuild that regroups the event cannot bring it back. */
final readonly class TrendingStoryHiding
{
    public function __construct(
        private TrendingStoryRepository $stories,
        private TrendingHiddenEntryRepository $hiddenEntries,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @throws TrendingStoryNotFoundException */
    public function hide(int $userId, TrendingWindow $window, int $entryId): void
    {
        $storyId = $this->stories->storyIdContaining($userId, $window, $entryId);
        $this->hiddenEntries->hideStory($userId, $storyId, $this->clock->now());
    }
}
