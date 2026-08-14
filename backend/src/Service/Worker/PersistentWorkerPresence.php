<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * The always-on worker container's liveness (#371 follow-up). It is the only
 * writer of WorkerPresence::RECOMMENDATION_SWEEP, which is what makes that
 * key mean "this install has a persistent worker" rather than merely
 * "something is sweeping" — the settings card reads exactly that difference.
 */
final readonly class PersistentWorkerPresence implements SweepingWorker
{
    public function __construct(private WorkerPresence $presence)
    {
    }

    public function markSweeping(): void
    {
        $this->presence->markPersistentWorkerSweep();
    }
}
