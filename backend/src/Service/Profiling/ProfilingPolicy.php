<?php

declare(strict_types=1);

namespace App\Service\Profiling;

use App\Service\Grafana\GrafanaSettings;

final readonly class ProfilingPolicy
{
    public function __construct(
        private GrafanaSettings $settings,
        private ProfileSampler $sampler,
        private PyroscopeEndpoint $endpoint,
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->sampler->isAvailable()) {
            return false;
        }
        try {
            return $this->settings->profilingEnabled() && null !== $this->endpoint->pushUrl();
        } catch (\Throwable) {
            return false;
        }
    }
}
