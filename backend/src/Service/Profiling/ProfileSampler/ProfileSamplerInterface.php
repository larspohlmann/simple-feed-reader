<?php

declare(strict_types=1);

namespace App\Service\Profiling\ProfileSampler;

use App\Service\Profiling\CollapsedProfile;

interface ProfileSamplerInterface
{
    public function isAvailable(): bool;

    public function start(float $periodSeconds): void;

    public function stop(): ?CollapsedProfile;

    public function isRunning(): bool;
}
