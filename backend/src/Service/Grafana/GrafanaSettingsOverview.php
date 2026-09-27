<?php

declare(strict_types=1);

namespace App\Service\Grafana;

final readonly class GrafanaSettingsOverview
{
    public function __construct(
        public GrafanaSettingsSnapshot $stored,
        public GrafanaEnvDefaults $defaults,
        public bool $profilerAvailable,
    ) {
    }
}
