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
 * Hence one key per RecommendationDriverKind. The per-user run lock is still
 * what actually stops a double run if either signal is ever wrong (#311).
 */
final readonly class WorkerPresence
{
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

    public function mark(RecommendationDriverKind $kind): void
    {
        $this->heartbeats->touch($kind->heartbeatName(), $this->clock->now());
    }

    /**
     * Surrenders a liveness claim immediately instead of waiting out
     * FRESH_SECONDS. A drainer is a worker only for as long as it lives, and
     * one that exits with runs still active would otherwise keep the poll
     * driver deferring to a process that is gone, and keep the cron's respawn
     * net from firing, for the full freshness window.
     *
     * Guarded rather than open, because forgetting the persistent worker's
     * key would be an outright lie in the other direction: that key is the
     * only evidence the settings card has, and its owner is a process that
     * cannot be asked whether it is still there.
     */
    public function forget(RecommendationDriverKind $kind): void
    {
        if (!$kind->surrendersItsKeyOnExit()) {
            throw new \LogicException(\sprintf('%s never surrenders its heartbeat.', $kind->name));
        }

        $this->heartbeats->forget($kind->heartbeatName());
    }

    /**
     * Somebody else owns execution right now: any driver kind at all. Read by
     * the poll driver (which then reports instead of driving) and by the drain
     * spawner (which then does not fork). Asked of every case rather than of a
     * named pair, so a driver kind added later counts without anyone having to
     * remember this line.
     */
    public function isAnybodyDrivingRecommendationRuns(): bool
    {
        $names = array_map(
            static fn (RecommendationDriverKind $kind): string => $kind->heartbeatName(),
            RecommendationDriverKind::cases(),
        );

        // One query for every name: this runs on the poll path, which every
        // open tab hits repeatedly, and on the install this feature exists
        // for the persistent worker's row is never fresh -- so a per-name
        // read would pay for the whole list every time.
        foreach ($this->heartbeats->findTouchedAtByNames($names) as $touchedAt) {
            if ($this->isFresh($touchedAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * This install runs a background worker that also starts due runs, so
     * scheduled auto-generation needs no cron entry. See the class doc for why
     * a drainer deliberately does not answer this question.
     */
    public function hasPersistentRecommendationWorker(): bool
    {
        $touchedAt = $this->heartbeats->findTouchedAt(
            RecommendationDriverKind::PersistentWorker->heartbeatName(),
        );

        return null !== $touchedAt && $this->isFresh($touchedAt);
    }

    private function isFresh(\DateTimeImmutable $touchedAt): bool
    {
        $ageInSeconds = $this->clock->now()->getTimestamp() - $touchedAt->getTimestamp();

        return $ageInSeconds <= self::FRESH_SECONDS;
    }
}
