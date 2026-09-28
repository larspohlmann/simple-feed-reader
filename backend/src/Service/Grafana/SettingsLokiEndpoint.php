<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Logging\Loki\LokiEndpoint\LokiEndpointInterface;

final readonly class SettingsLokiEndpoint implements LokiEndpointInterface
{
    public function __construct(private EffectiveGrafanaSettings $settings)
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
