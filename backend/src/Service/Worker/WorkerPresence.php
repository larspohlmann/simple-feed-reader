<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Repository\WorkerHeartbeatRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Who is sweeping recommendation runs right now. Two different questions are
 * asked of this class and they do NOT have the same answer (#371 follow-up):
 *
 * - "Is anybody driving the runs at this moment?" — the poll driver and the
 *   drain spawner ask this, and a short-lived on-demand drainer counts for
 *   both: the poll tick must not drive a run somebody else is advancing, and
 *   the spawner must not fork next to a live drainer.
 * - "Does this install run a persistent worker?" — the settings card asks
 *   this to decide whether the operator still needs a cron entry for
 *   scheduled auto-generation. A drainer must NOT count: it only advances
 *   runs that already exist and never starts due ones
 *   (ForYouSweep::startDueRuns() is no part of WorkerRunSweep), so the cron
 *   is still genuinely required. One shared key answered "you need no cron"
 *   to anyone who opened Settings during a drain.
 *
 * Hence one key per kind of worker. The per-user run lock is still what
 * actually stops a double run if either signal is ever wrong (#311).
 */
final readonly class WorkerPresence
{
    public const string RECOMMENDATION_SWEEP = 'recommendation-sweep';

    /**
     * The on-demand drainer's own key (#371). Separate from the persistent
     * worker's, so a transient drainer can claim and surrender liveness
     * without ever writing to a real worker's row — which is also why the
     * drainer's unconditional clear-on-exit is safe next to a live worker.
     */
    public const string RECOMMENDATION_DRAIN_SWEEP = 'recommendation-drain-sweep';

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

    public function markPersistentWorkerSweep(): void
    {
        $this->heartbeats->touch(self::RECOMMENDATION_SWEEP, $this->clock->now());
    }

    public function markOnDemandDrainerSweep(): void
    {
        $this->heartbeats->touch(self::RECOMMENDATION_DRAIN_SWEEP, $this->clock->now());
    }

    /**
     * Surrenders the drainer's liveness claim immediately instead of waiting
     * out FRESH_SECONDS. The drainer is a worker only for as long as it
     * lives, and one that exits with runs still active would otherwise keep
     * the poll driver deferring to a process that is gone, and keep the
     * cron's respawn net from firing, for the full freshness window. It
     * touches the drain key only, so a drainer that happened to run beside a
     * real worker cannot clear that worker's heartbeat.
     */
    public function forgetOnDemandDrainer(): void
    {
        $this->heartbeats->forget(self::RECOMMENDATION_DRAIN_SWEEP);
    }

    /**
     * Somebody else owns execution right now: either the persistent worker or
     * a live on-demand drainer. Read by the poll driver (which then reports
     * instead of driving) and by the drain spawner (which then does not fork).
     */
    public function isAnybodyDrivingRecommendationRuns(): bool
    {
        return $this->hasPersistentRecommendationWorker()
            || $this->isFresh(self::RECOMMENDATION_DRAIN_SWEEP);
    }

    /**
     * This install runs a background worker that also starts due runs, so
     * scheduled auto-generation needs no cron entry. A drainer never starts a
     * due run, so it deliberately does not answer this question.
     */
    public function hasPersistentRecommendationWorker(): bool
    {
        return $this->isFresh(self::RECOMMENDATION_SWEEP);
    }

    private function isFresh(string $name): bool
    {
        $touchedAt = $this->heartbeats->findTouchedAt($name);

        if (null === $touchedAt) {
            return false;
        }

        $ageInSeconds = $this->clock->now()->getTimestamp() - $touchedAt->getTimestamp();

        return $ageInSeconds <= self::FRESH_SECONDS;
    }
}
