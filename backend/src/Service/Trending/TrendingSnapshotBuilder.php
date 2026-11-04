<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\TrendingBuildReportModel;
use App\Service\Trending\Pass\BuildStopwatch;
use Doctrine\ORM\EntityManagerInterface;

/** Groups one window and replaces its snapshot in one transaction: readers see the old one until the commit. */
final readonly class TrendingSnapshotBuilder
{
    public function __construct(
        private TrendingGrouping $grouping,
        private TrendingSnapshotRepository $snapshots,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function build(TrendingWindow $window): TrendingBuildReportModel
    {
        $stopwatch = BuildStopwatch::start();
        $run = $this->grouping->collect($window);
        $stories = $this->grouping->stories($run);

        return $this->entityManager->wrapInTransaction(function () use ($window, $run, $stories, $stopwatch) {
            $this->snapshots->replaceStories($window, $stories);
            $report = new TrendingBuildReportModel(
                $window,
                $this->clock->now(),
                \count($run->entryIds()),
                \count($stories),
                $stopwatch->elapsedMilliseconds(),
                $stopwatch->peakMemoryBytes(),
            );
            $this->snapshots->recordSnapshot($report);

            return $report;
        });
    }
}
