<?php

declare(strict_types=1);

namespace App\Service\Profiling\ProfileSampler;

use App\Service\Profiling\Model\CollapsedProfileModel;

interface ProfileSamplerInterface
{
    public function isAvailable(): bool;

    public function start(float $periodSeconds): void;

    public function stop(): ?CollapsedProfileModel;

    public function isRunning(): bool;
}
