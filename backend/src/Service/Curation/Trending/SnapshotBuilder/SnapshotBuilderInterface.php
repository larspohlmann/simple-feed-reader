<?php

declare(strict_types=1);

namespace App\Service\Trending\SnapshotBuilder;

use App\Enum\TrendingWindow;
use App\Service\Trending\Model\TrendingBuildReportModel;

interface SnapshotBuilderInterface
{
    public function build(TrendingWindow $window): TrendingBuildReportModel;
}
