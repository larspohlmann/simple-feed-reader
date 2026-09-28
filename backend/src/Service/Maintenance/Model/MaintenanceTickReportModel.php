<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Refresh\RefreshReport;

final readonly class MaintenanceTickReportModel
{
    public function __construct(
        public RefreshReport $refresh,
        public MaintenanceSweepsModel $sweeps,
        public LokiSpoolReportModel $logShipping,
    ) {
    }
}
