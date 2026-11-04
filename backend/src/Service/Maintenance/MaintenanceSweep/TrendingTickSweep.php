<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Service\Trending\TrendingRebuild;

final readonly class TrendingTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private TrendingRebuild $trendingRebuild)
    {
    }

    public static function name(): string
    {
        return 'trending';
    }

    public static function priority(): int
    {
        return 10;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->trendingRebuild->rebuildDueBefore($deadline));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(TrendingRebuildReportModel::none());
    }

    private static function reportOf(TrendingRebuildReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'windowsRebuilt' => \count($report->builds),
            'storiesBuilt' => $report->storyCount(),
        ]);
    }
}
