<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\RefreshRunner;

use App\Service\Ingestion\Refresh\Model\RefreshReportModel;
use App\Service\Ingestion\Refresh\Model\RefreshRequestModel;

/**
 * One budgeted slice of refresh work. The seam lets TrackedRefreshRunner be tested against prepared slices instead of
 * the network, the clock and a database, since RefreshRunner is final.
 */
interface RefreshRunnerInterface
{
    public function run(RefreshRequestModel $request): RefreshReportModel;
}
