<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Profiling\PyroscopeEndpoint\PyroscopeEndpointInterface;

final readonly class SettingsPyroscopeEndpoint implements PyroscopeEndpointInterface
{
    public function __construct(private EffectiveGrafanaSettings $settings)
    {
    }

    public function pushUrl(): ?string
    {
        return $this->settings->effectivePyroscopePushUrl();
    }
}
