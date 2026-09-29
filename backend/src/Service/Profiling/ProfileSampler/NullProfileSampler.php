<?php

declare(strict_types=1);

namespace App\Service\Profiling\ProfileSampler;

use App\Service\Profiling\Model\CollapsedProfileModel;

final readonly class NullProfileSampler implements ProfileSamplerInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function start(float $periodSeconds): void
    {
    }

    public function stop(): ?CollapsedProfileModel
    {
        return null;
    }

    public function isRunning(): bool
    {
        return false;
    }
}
