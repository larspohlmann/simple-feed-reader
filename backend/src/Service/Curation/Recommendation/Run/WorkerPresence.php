<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Repository\WorkerHeartbeatRepository;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use Symfony\Component\Clock\ClockInterface;

/**
 * Who is driving recommendation runs: one heartbeat key per RecommendationDriverKind, read for two questions that must
 * keep different answers (docs/for-you-scheduling.md#the-on-demand-drainer). The per-user run lock, not this class,
 * is what stops a double run.
 */
final readonly class WorkerPresence
{
    /**
     * The longest silence before a first chunk (ProviderTimeoutsModel's slow first-byte bound, 900 s) plus one sweep.
     * Raise it with that bound, not with a call's wall clock: docs/recommendations-runs.md#worker-presence
     */
    public const int FRESH_SECONDS = 960;

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
     * Surrenders a one-pass driver's key at once, so polls and the spawner stop deferring to a gone process. Refused
     * for the persistent worker: its key is the settings card's only evidence that the worker runs.
     */
    public function forget(RecommendationDriverKind $kind): void
    {
        if (!$kind->surrendersItsKeyOnExit()) {
            throw new \LogicException(\sprintf('%s never surrenders its heartbeat.', $kind->name));
        }

        $this->heartbeats->forget($kind->heartbeatName());
    }

    /** Any driver kind counts, one added later too: the poll driver then only reports and the spawner does not fork. */
    public function isAnybodyDrivingRecommendationRuns(): bool
    {
        $names = array_map(
            static fn (RecommendationDriverKind $kind): string => $kind->heartbeatName(),
            RecommendationDriverKind::cases(),
        );

        // One query for every name: every open tab polls this, and a per-name read would pay for all names each time.
        foreach ($this->heartbeats->findTouchedAtByNames($names) as $touchedAt) {
            if ($this->isFresh($touchedAt)) {
                return true;
            }
        }

        return false;
    }

    /** A persistent worker also starts due runs, so scheduled runs need no cron entry; a drainer does not count. */
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
