<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\SnapshotBuilder;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use Doctrine\DBAL\Exception\RetryableException;

interface SnapshotBuilderInterface
{
    /**
     * @throws TrendingBuildBusyException while another build of the user runs
     * @throws RetryableException when the database gives up a lock wait or picks the build as a deadlock's victim
     */
    public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel;
}
