<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\ReaderWindowModel;

/**
 * Cuts a window to one reader: the threshold from how many of their feeds posted within it (D38), and the stories
 * they are shown in a shorter window, which this one leaves out (D41).
 */
final readonly class ReaderWindows
{
    public function __construct(
        private TrendingEntryRepository $windowEntries,
        private TrendingStoryRepository $stories,
        private NaiveUtcClock $clock,
    ) {
    }

    public function of(int $userId, TrendingWindow $window): ReaderWindowModel
    {
        $since = $window->startBefore($this->clock->now());

        return new ReaderWindowModel(
            $userId,
            $window,
            $window->minimumOutletsFor($this->windowEntries->activeFeedCount($userId, $since)),
            $this->storyIdsLeftOutOf($userId, $window),
        );
    }

    /** @return list<int> */
    private function storyIdsLeftOutOf(int $userId, TrendingWindow $window): array
    {
        return array_merge([], ...array_map(
            fn (TrendingWindow $shorter): array => $this->shownStoryIds($userId, $shorter),
            $window->leavesOutTheStoriesOf(),
        ));
    }

    /** @return list<int> */
    private function shownStoryIds(int $userId, TrendingWindow $window): array
    {
        return RankedStoryModel::storyIdsOf(
            $this->stories->rankedForUser($this->of($userId, $window), TrendingStories::MAX_STORIES),
        );
    }
}
