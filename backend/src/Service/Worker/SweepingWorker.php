<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * The worker a run sweep is running inside, from the sweep's point of view
 * (#371 follow-up). WorkerRunSweep marks liveness once per run it advances,
 * so the mark cannot be left to the caller to make around the sweep; but the
 * two callers claim two different liveness keys, and the sweep must not know
 * or choose between them. So it asks whoever is running it to mark itself.
 *
 * The implementations are the two worker regimes: PersistentWorkerPresence
 * (the Docker worker's ten-second firing) and OnDemandDrainerPresence (the
 * detached drain command).
 */
interface SweepingWorker
{
    public function markSweeping(): void;
}
