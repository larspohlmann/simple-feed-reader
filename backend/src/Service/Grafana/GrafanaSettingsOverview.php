<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;

final readonly class GrafanaSettingsOverview
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ?GrafanaSettingsEntity $settings,
        public GrafanaEnvDefaults $defaults,
        public bool $profilerAvailable,
    ) {
    }
}
