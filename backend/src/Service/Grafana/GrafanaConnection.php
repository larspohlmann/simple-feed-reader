<?php

declare(strict_types=1);

namespace App\Service\Grafana;

final readonly class GrafanaConnection
{
    public function __construct(
        public ?string $lokiPushUrl,
        public ?string $lokiUsername,
        public ?string $grafanaUrl,
    ) {
    }
}
