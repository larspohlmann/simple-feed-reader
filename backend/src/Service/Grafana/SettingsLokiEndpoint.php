<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Logging\Loki\LokiEndpoint;

final readonly class SettingsLokiEndpoint implements LokiEndpoint
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    public function pushUrl(): ?string
    {
        return $this->settings->effectiveLokiPushUrl();
    }

    public function username(): ?string
    {
        return $this->settings->lokiUsername();
    }

    public function token(): ?string
    {
        return $this->settings->lokiToken();
    }
}
