<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Refresh\RefreshReport;

final readonly class MaintenanceTickReport
{
    public function __construct(
        public RefreshReport $refresh,
        public MaintenanceSweeps $sweeps,
        public LokiSpoolReport $logShipping,
    ) {
    }
}
