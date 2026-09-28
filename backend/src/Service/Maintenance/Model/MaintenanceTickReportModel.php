<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Refresh\Model\RefreshReportModel;

final readonly class MaintenanceTickReportModel
{
    public function __construct(
        public RefreshReportModel $refresh,
        public MaintenanceSweepsModel $sweeps,
        public LokiSpoolReportModel $logShipping,
    ) {
    }
}
