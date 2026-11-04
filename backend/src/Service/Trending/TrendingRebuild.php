<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use App\Service\Clock\NaiveUtcClock;
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
        $builtAt = $this->snapshots->builtAtByWindow();
        $builds = [];
        foreach (TrendingWindow::cases() as $window) {
            if ($this->isDue($window, $builtAt[$window->value] ?? null)) {
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
}
