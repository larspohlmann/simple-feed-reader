<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\Model;

use App\Service\Operations\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Ingestion\Refresh\Model\RefreshReportModel;

final readonly class MaintenanceTickReportModel
{
    public function __construct(
        public RefreshReportModel $refresh,
        public MaintenanceSweepsModel $sweeps,
        public LokiSpoolReportModel $logShipping,
    ) {
    }
}
