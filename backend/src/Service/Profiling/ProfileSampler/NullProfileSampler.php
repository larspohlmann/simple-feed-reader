<?php

declare(strict_types=1);

namespace App\Service\Profiling\ProfileSampler;

use App\Service\Profiling\CollapsedProfile;

final class NullProfileSampler implements ProfileSamplerInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function start(float $periodSeconds): void
    {
    }

    public function stop(): ?CollapsedProfile
    {
        return null;
    }

    public function isRunning(): bool
    {
        return false;
    }
}
