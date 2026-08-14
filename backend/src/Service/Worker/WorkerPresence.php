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
     * is OpenAiCompatibleChatClient::TIMEOUT_SECONDS (600 s); add the ten
     * seconds until the next firing and the worst honest silence of a
     * perfectly healthy worker is ~610 s. 660 s carries that with room for
     * the surrounding bookkeeping.
     *
     * This constant follows that ceiling and must be raised with it: when
     * #320 took the call bound from 120 s to 300 s to fit a reasoning
     * model's thinking phase, leaving this at 180 s would have declared a
     * working worker dead in the middle of every long call. The same applied
     * when a slow local model pushed the ceiling from 300 s to 600 s.
     *
     * The earlier 30 s -- justified as "three missed ten-second sweeps" --
     * was shorter than a single unit of work, so the arbitration flipped to
     * poll mode exactly while the worker was working: the client then hit
     * the per-user lock, read `busy` as a failure, and stopped polling a
     * healthy background run (#311 final review, Critical 2).
     *
     * The cost of the wider window is that a worker that dies mid-call is
     * recognised as dead up to 660 s later. That is the right trade: a late
     * fallback resumes a run, while a premature one aborts it.
     */
    public const int FRESH_SECONDS = 660;

    public function __construct(
        private WorkerHeartbeatRepository $heartbeats,
        private ClockInterface $clock,
    ) {
    }

    public function markRecommendationSweep(): void
    {
        $this->heartbeats->touch(self::RECOMMENDATION_SWEEP, $this->clock->now());
    }

    /**
     * Surrenders the liveness claim immediately instead of waiting out
     * FRESH_SECONDS. The on-demand drainer (#371) is the caller: it is a
     * worker only for as long as it lives, and a drainer that exits with runs
     * still active would otherwise keep the poll driver deferring to a dead
     * worker, and keep the cron's respawn net from firing, for the full
     * freshness window. A Docker install's real worker re-marks within its
     * ten-second cadence, so clearing costs nothing there.
     */
    public function forgetRecommendationSweep(): void
    {
        $this->heartbeats->forget(self::RECOMMENDATION_SWEEP);
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
