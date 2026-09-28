<?php

declare(strict_types=1);

namespace App\Service\Profiling;

use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;
use App\Service\Profiling\ProfilingConfigSource\ProfilingConfigSourceInterface;
use App\Service\Profiling\PyroscopeEndpoint\PyroscopeEndpointInterface;

final readonly class ProfilingPolicy
{
    public function __construct(
        private ProfilingConfigSourceInterface $config,
        private ProfileSamplerInterface $sampler,
        private PyroscopeEndpointInterface $endpoint,
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->sampler->isAvailable()) {
            return false;
        }
        try {
            return $this->config->profilingEnabled() && null !== $this->endpoint->pushUrl();
        } catch (\Throwable) {
            // Unreadable config means profiling is off: this runs on every request and must never fail one.
            return false;
        }
    }
}
