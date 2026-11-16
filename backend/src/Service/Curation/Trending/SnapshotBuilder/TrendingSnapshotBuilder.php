<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\SnapshotBuilder;

use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Pass\BuildStopwatch;
use App\Service\Curation\Trending\StoryMatching;
use App\Service\Curation\Trending\TrendingGrouping;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Groups one window and matches the groups to the stored stories, updating them in place in one transaction:
 * readers see the old stories until the commit. Only the week build merges and prunes (#2128).
 */
final readonly class TrendingSnapshotBuilder implements SnapshotBuilderInterface
{
    public function __construct(
        private TrendingGrouping $grouping,
        private StoryMatching $matching,
        private TrendingStoryHistoryRepository $history,
        private TrendingSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function build(TrendingWindow $window): TrendingBuildReportModel
    {
        $stopwatch = BuildStopwatch::start();
        $run = $this->grouping->collect($window);
        $groups = $this->grouping->stories($run);
        $entryCount = $run->entryCount();
        $build = new StoryBuildModel($window, $run->since, $this->clock->now());
        unset($run);
        $match = $this->matching->match($groups, $this->history->storedInWindow($build->since), $window);

        return $this->entityManager->wrapInTransaction(function () use ($build, $match, $entryCount, $stopwatch) {
            $pruned = $this->write($build, $match);
            $report = new TrendingBuildReportModel(
                $build->window,
                $build->builtAt,
                $entryCount,
                \count($match->assignments),
                $stopwatch->elapsedMilliseconds(),
                $stopwatch->peakMemoryBytes(),
                $match,
                $pruned,
            );
            $this->snapshots->recordSnapshot($report);

            return $report;
        });
    }

    /** @return int the stories pruned */
    private function write(StoryBuildModel $build, StoryMatchModel $match): int
    {
        foreach ($match->merges as $merge) {
            $this->history->merge($merge);
        }
        $this->history->clearPlacement($build->window);
        foreach ($match->assignments as $assignment) {
            $this->history->save($assignment, $build);
        }

        return $build->window->mergesStories() ? $this->history->pruneUnfollowedOutside($build->since) : 0;
    }
}
