<?php

declare(strict_types=1);

namespace App\Service\Refresh\RefreshRunner;

use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;

/**
 * One budgeted slice of refresh work. The seam lets TrackedRefreshRunner be tested against prepared slices instead of
 * the network, the clock and a database, since RefreshRunner is final.
 */
interface RefreshRunnerInterface
{
    public function run(RefreshRequestModel $request): RefreshReportModel;
}
