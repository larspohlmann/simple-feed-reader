<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final class NullProfileSampler implements ProfileSampler
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
