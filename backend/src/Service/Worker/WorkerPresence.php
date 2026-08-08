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

    /**
     * Sized against the longest gap between two touches, NOT against the
     * sweep cadence. The sweep touches the heartbeat once per run it
     * advances, and advancing one run makes one provider call whose ceiling
     * is OpenAiCompatibleChatClient::TIMEOUT_SECONDS (120 s); add the ten
     * seconds until the next firing and the worst honest silence of a
     * perfectly healthy worker is ~130 s. 180 s carries that with room for
     * the surrounding bookkeeping.
     *
     * The earlier 30 s -- justified as "three missed ten-second sweeps" --
     * was shorter than a single unit of work, so the arbitration flipped to
     * poll mode exactly while the worker was working: the client then hit
     * the per-user lock, read `busy` as a failure, and stopped polling a
     * healthy background run (#311 final review, Critical 2).
     *
     * The cost of the wider window is that a worker that dies mid-call is
     * recognised as dead up to 180 s later. That is the right trade: a late
     * fallback resumes a run, while a premature one aborts it.
     */
    public const int FRESH_SECONDS = 180;

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

        if (null === $touchedAt) {
            return false;
        }

        $ageInSeconds = $this->clock->now()->getTimestamp() - $touchedAt->getTimestamp();

        return $ageInSeconds <= self::FRESH_SECONDS;
    }
}
