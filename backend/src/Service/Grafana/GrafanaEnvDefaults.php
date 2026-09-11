<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The env values the installer writes for the local container; GrafanaSettings falls back to these. */
final readonly class GrafanaEnvDefaults
{
    public function __construct(
        #[Autowire('%env(GRAFANA_LOKI_PUSH_URL)%')]
        public string $lokiPushUrl,
        #[Autowire('%env(GRAFANA_URL)%')]
        public string $grafanaUrl,
    ) {
    }
}
