<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Profiling\ProfileLabels;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

#[AsEventListener(event: WorkerStartedEvent::class, method: 'onWorkerStarted')]
#[AsEventListener(event: WorkerRunningEvent::class, method: 'onWorkerRunning')]
#[AsEventListener(event: WorkerStoppedEvent::class, method: 'onWorkerStopped')]
final class WorkerProfilingListener
{
    public const float SAMPLE_PERIOD_SECONDS = 0.01;
    public const int FLUSH_INTERVAL_SECONDS = 10;
    public const int TOGGLE_RECHECK_SECONDS = 30;

    private int $lastFlushAt = 0;
    private int $lastToggleCheckAt = 0;

    public function __construct(
        private readonly ProfilingPolicy $policy,
        private readonly ProfileSampler $sampler,
        private readonly PyroscopeClient $client,
        private readonly ClockInterface $clock,
    ) {
    }

    public function onWorkerStarted(): void
    {
        $this->refreshToggle();
    }

    public function onWorkerRunning(): void
    {
        $now = $this->now();
        if ($now - $this->lastToggleCheckAt >= self::TOGGLE_RECHECK_SECONDS) {
            $this->refreshToggle();
        }
        if ($this->sampler->isRunning() && $now - $this->lastFlushAt >= self::FLUSH_INTERVAL_SECONDS) {
            $this->rotate();
        }
    }

    public function onWorkerStopped(): void
    {
        $this->flush();
    }

    private function refreshToggle(): void
    {
        $this->lastToggleCheckAt = $this->now();
        $enabled = $this->policy->isEnabled();
        if ($enabled && !$this->sampler->isRunning()) {
            $this->startSampling();
        }
        if (!$enabled && $this->sampler->isRunning()) {
            $this->flush();
        }
    }

    private function rotate(): void
    {
        $this->flush();
        $this->startSampling();
    }

    private function startSampling(): void
    {
        $this->sampler->start(self::SAMPLE_PERIOD_SECONDS);
        $this->lastFlushAt = $this->now();
    }

    private function flush(): void
    {
        $profile = $this->sampler->stop();
        if (null !== $profile) {
            $this->client->push($profile, ProfileLabels::forWorker());
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
