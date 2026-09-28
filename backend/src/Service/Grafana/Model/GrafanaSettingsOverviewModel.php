<?php

declare(strict_types=1);

namespace App\Service\Grafana\Model;

use App\Service\Grafana\GrafanaEnvDefaults;

final readonly class GrafanaSettingsOverviewModel
{
    public function __construct(
        public GrafanaSettingsSnapshotModel $stored,
        public GrafanaEnvDefaults $defaults,
        public bool $profilerAvailable,
    ) {
    }
}
