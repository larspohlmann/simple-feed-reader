<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final class ExcimerSampler implements ProfileSampler
{
    private const int MAX_STACK_DEPTH = 250;

    private ?\ExcimerProfiler $profiler = null;
    private int $startedAtUnix = 0;
    private int $sampleRateHz = 0;

    public function isAvailable(): bool
    {
        return extension_loaded('excimer');
    }

    public function start(float $periodSeconds): void
    {
        if (null !== $this->profiler) {
            return;
        }
        $profiler = new \ExcimerProfiler();
        $profiler->setEventType(EXCIMER_REAL);
        $profiler->setPeriod($periodSeconds);
        $profiler->setMaxDepth(self::MAX_STACK_DEPTH);
        $profiler->start();
        $this->profiler = $profiler;
        $this->startedAtUnix = time();
        $this->sampleRateHz = (int) round(1 / $periodSeconds);
    }

    public function stop(): ?CollapsedProfile
    {
        if (null === $this->profiler) {
            return null;
        }
        $this->profiler->stop();
        $log = $this->profiler->getLog();
        $this->profiler = null;
        $sampleCount = $log->count();
        if (0 === $sampleCount) {
            return null;
        }

        return new CollapsedProfile(
            $log->formatCollapsed(),
            $sampleCount,
            $this->sampleRateHz,
            $this->startedAtUnix,
            time(),
        );
    }

    public function isRunning(): bool
    {
        return null !== $this->profiler;
    }
}
