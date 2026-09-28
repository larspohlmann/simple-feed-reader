<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Profiling\Model\CollapsedProfileModel;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;

final class RecordingProfileSampler implements ProfileSamplerInterface
{
    /** @var list<float> */
    public array $startedWithPeriods = [];

    public function __construct(
        private readonly ?CollapsedProfileModel $profile = new CollapsedProfileModel(
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

    public function stop(): ?CollapsedProfileModel
    {
        return $this->profile;
    }

    public function isRunning(): bool
    {
        return [] !== $this->startedWithPeriods;
    }
}
