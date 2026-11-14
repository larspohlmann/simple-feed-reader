<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use App\Service\Curation\Trending\TrendingRebuild;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'trending', priority: 10)]
final readonly class TrendingTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private TrendingRebuild $trendingRebuild)
    {
    }

    public function run(\DateTimeImmutable $deadline): TrendingRebuildReportModel
    {
        return $this->trendingRebuild->rebuildDueBefore($deadline);
    }

    public function idleReport(): TrendingRebuildReportModel
    {
        return TrendingRebuildReportModel::none();
    }
}
