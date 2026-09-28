<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Profiling\Model\CollapsedProfileModel;
use App\Service\Profiling\ProfileSampler\ProfileSamplerInterface;

final class TrackingProfileSampler implements ProfileSamplerInterface
{
    /** @var list<float> */
    public array $startedWithPeriods = [];
    public int $stopCalls = 0;

    private bool $running = false;

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
        $this->running = true;
    }

    public function stop(): ?CollapsedProfileModel
    {
        $this->running = false;
        ++$this->stopCalls;

        return $this->profile;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
