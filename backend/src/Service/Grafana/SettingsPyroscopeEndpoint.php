<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Profiling\PyroscopeEndpoint;

final readonly class SettingsPyroscopeEndpoint implements PyroscopeEndpoint
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    public function pushUrl(): ?string
    {
        return $this->settings->effectivePyroscopePushUrl();
    }
}
