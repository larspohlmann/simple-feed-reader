<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\SnapshotBuilder;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;

interface SnapshotBuilderInterface
{
    /** @throws TrendingBuildBusyException */
    public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel;
}
