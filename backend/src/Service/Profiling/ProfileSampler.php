<?php

declare(strict_types=1);

namespace App\Service\Profiling;

interface ProfileSampler
{
    public function isAvailable(): bool;

    public function start(float $periodSeconds): void;

    public function stop(): ?CollapsedProfile;

    public function isRunning(): bool;
}
