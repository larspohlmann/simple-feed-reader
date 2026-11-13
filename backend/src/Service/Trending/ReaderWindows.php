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
 * Cuts a window to one reader: the threshold from how many of their feeds posted within it, relaxed until five
 * stories show (D38, #2202), and the stories they are shown in a shorter window, which this one leaves out (D41).
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
        $atMultipleSources = $this->atMultipleSources($userId, $window);

        return $atMultipleSources->withMinimumOutlets(
            $this->relaxedMinimum($atMultipleSources, $this->widestStories($atMultipleSources)),
        );
    }

    /**
     * The stories the reader is shown: those ranked at MULTIPLE_SOURCES that reach the relaxed minimum, a prefix of
     * that ranking since it runs widest first.
     *
     * @return list<RankedStoryModel>
     */
    public function shownStories(int $userId, TrendingWindow $window): array
    {
        $atMultipleSources = $this->atMultipleSources($userId, $window);
        $widest = $this->widestStories($atMultipleSources);
        $minimum = $this->relaxedMinimum($atMultipleSources, $widest);

        return array_values(array_filter(
            $widest,
            static fn (RankedStoryModel $story): bool => $story->outletCount >= $minimum,
        ));
    }

    private function atMultipleSources(int $userId, TrendingWindow $window): ReaderWindowModel
    {
        return new ReaderWindowModel(
            $userId,
            $window,
            TrendingWindow::MULTIPLE_SOURCES,
            $this->storyIdsLeftOutOf($userId, $window),
        );
    }

    /** @return list<RankedStoryModel> */
    private function widestStories(ReaderWindowModel $atMultipleSources): array
    {
        return $this->stories->rankedForUser($atMultipleSources, TrendingStories::MAX_STORIES);
    }

    /**
     * The preferred minimum, lowered to the outlets of the last of the widest stories worth showing when fewer would
     * show at it, or to MULTIPLE_SOURCES when not even that many show at all.
     *
     * @param list<RankedStoryModel> $widest
     */
    private function relaxedMinimum(ReaderWindowModel $atMultipleSources, array $widest): int
    {
        $window = $atMultipleSources->window;
        $since = $window->startBefore($this->clock->now());
        $lastWorthShowing = $widest[TrendingWindow::STORIES_WORTH_SHOWING - 1] ?? null;

        return min(
            $window->minimumOutletsFor($this->windowEntries->activeFeedCount($atMultipleSources->userId, $since)),
            $lastWorthShowing->outletCount ?? TrendingWindow::MULTIPLE_SOURCES,
        );
    }

    /** @return list<int> */
    private function storyIdsLeftOutOf(int $userId, TrendingWindow $window): array
    {
        return array_merge([], ...array_map(
            fn (TrendingWindow $shorter): array => RankedStoryModel::storyIdsOf($this->shownStories($userId, $shorter)),
            $window->leavesOutTheStoriesOf(),
        ));
    }
}
