<?php

declare(strict_types=1);

namespace App\Service\Operations\Grafana\Model;

use App\Service\Operations\Grafana\GrafanaEnvDefaults;

final readonly class GrafanaSettingsOverviewModel
{
    public function __construct(
        public GrafanaSettingsSnapshotModel $stored,
        public GrafanaEnvDefaults $defaults,
    ) {
    }
}
