<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Repository\WorkerHeartbeatRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Whether a background worker is currently sweeping. The poll driver reads
 * this to decide whether it still owes the work itself; the per-user run
 * lock is what actually stops a double run if this signal is ever wrong
 * (#311).
 */
final readonly class WorkerPresence
{
    public const string RECOMMENDATION_SWEEP = 'recommendation-sweep';

    /** Three missed ten-second sweeps count as dead. */
    private const int FRESH_SECONDS = 30;

    public function __construct(
        private WorkerHeartbeatRepository $heartbeats,
        private ClockInterface $clock,
    ) {
    }

    public function markRecommendationSweep(): void
    {
        $this->heartbeats->touch(self::RECOMMENDATION_SWEEP, $this->clock->now());
    }

    public function isRecommendationWorkerAlive(): bool
    {
        $touchedAt = $this->heartbeats->findTouchedAt(self::RECOMMENDATION_SWEEP);

        if ($touchedAt === null) {
            return false;
        }

        $ageInSeconds = $this->clock->now()->getTimestamp() - $touchedAt->getTimestamp();

        return $ageInSeconds <= self::FRESH_SECONDS;
    }
}
