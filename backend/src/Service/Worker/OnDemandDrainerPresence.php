<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * The detached drain command's liveness (#371). It claims a key of its own,
 * never the persistent worker's: a drainer counts as somebody driving the
 * runs, so the poll driver defers to it and the spawner does not fork beside
 * it, but it never starts a due run and so must not tell the settings card
 * that this install needs no cron.
 *
 * Owning a separate key is also what makes surrender() unconditionally safe:
 * a drainer that happens to exit while a real worker sweeps can no longer
 * clear that worker's heartbeat.
 */
final readonly class OnDemandDrainerPresence implements SweepingWorker
{
    public function __construct(private WorkerPresence $presence)
    {
    }

    public function markSweeping(): void
    {
        $this->presence->markOnDemandDrainerSweep();
    }

    public function surrender(): void
    {
        $this->presence->forgetOnDemandDrainer();
    }
}
