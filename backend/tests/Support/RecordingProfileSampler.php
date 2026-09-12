<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;

final class RecordingProfileSampler implements ProfileSampler
{
    /** @var list<float> */
    public array $startedWithPeriods = [];

    public function __construct(
        private readonly ?CollapsedProfile $profile = new CollapsedProfile(
            'main;work 1',
            1,
            1000,
            1_700_000_000,
            1_700_000_001,
        ),
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function start(float $periodSeconds): void
    {
        $this->startedWithPeriods[] = $periodSeconds;
    }

    public function stop(): ?CollapsedProfile
    {
        return $this->profile;
    }

    public function isRunning(): bool
    {
        return [] !== $this->startedWithPeriods;
    }
}
