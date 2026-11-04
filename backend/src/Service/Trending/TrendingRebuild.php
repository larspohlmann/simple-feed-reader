<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\TrendingLastBuildsModel;
use App\Service\Trending\Model\TrendingRebuildReportModel;

/** Rebuilds every window whose snapshot is missing or as old as its interval; the worker and the tick call it. */
final readonly class TrendingRebuild
{
    public function __construct(
        private TrendingSnapshotBuilder $builder,
        private TrendingSnapshotRepository $snapshots,
        private NaiveUtcClock $clock,
    ) {
    }

    public function rebuildDue(): TrendingRebuildReportModel
    {
        return $this->rebuildDueWindowsWhere($this->snapshots->lastBuilds(), static fn (): bool => true);
    }

    /** Starts a due window only while time is left for it: at least as much as its last build took. */
    public function rebuildDueBefore(\DateTimeImmutable $deadline): TrendingRebuildReportModel
    {
        $lastBuilds = $this->snapshots->lastBuilds();

        return $this->rebuildDueWindowsWhere(
            $lastBuilds,
            function (TrendingWindow $window) use ($deadline, $lastBuilds): bool {
                $remaining = $this->remainingMilliseconds($deadline);

                return $remaining > 0 && $remaining >= $lastBuilds->durationMilliseconds($window);
            },
        );
    }

    /** @param \Closure(TrendingWindow): bool $mayStart asked just before each due window */
    private function rebuildDueWindowsWhere(
        TrendingLastBuildsModel $lastBuilds,
        \Closure $mayStart,
    ): TrendingRebuildReportModel {
        $builds = [];
        foreach (TrendingWindow::cases() as $window) {
            if ($this->isDue($window, $lastBuilds->builtAt($window)) && $mayStart($window)) {
                $builds[] = $this->builder->build($window);
            }
        }

        return new TrendingRebuildReportModel($builds);
    }

    private function isDue(TrendingWindow $window, ?\DateTimeImmutable $builtAt): bool
    {
        return $builtAt === null
            || $this->clock->now()->getTimestamp() - $builtAt->getTimestamp() >= $window->rebuildIntervalSeconds();
    }

    private function remainingMilliseconds(\DateTimeImmutable $deadline): int
    {
        return intdiv((int) $deadline->format('Uu') - (int) $this->clock->now()->format('Uu'), 1_000);
    }
}
