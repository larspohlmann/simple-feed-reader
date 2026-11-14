<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\TrendingExplanationModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Pass\BuildStopwatch;
use App\Service\Curation\Trending\StoryLinkSource\HeadlineLinks;

/** One window grouped in memory, every group and the near misses: tuning without writing a row (#2017). */
final readonly class TrendingExplainer
{
    public function __construct(private TrendingGrouping $grouping, private HeadlineLinks $headlineLinks)
    {
    }

    public function explain(TrendingWindow $window): TrendingExplanationModel
    {
        $stopwatch = BuildStopwatch::start();
        $run = $this->grouping->collect($window);
        $groups = $this->grouping->groups($run);
        usort(
            $groups,
            static fn (TrendingStoryModel $left, TrendingStoryModel $right): int
                => [$right->outletCount(), $right->lastSeen] <=> [$left->outletCount(), $left->lastSeen],
        );
        $nearMisses = $this->headlineLinks->nearMisses($run);

        return new TrendingExplanationModel(
            $window,
            \count($run->entryIds()),
            $groups,
            $nearMisses,
            $stopwatch->elapsedMilliseconds(),
            $stopwatch->peakMemoryBytes(),
        );
    }
}
