<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Worker\WorkerPresence;

/**
 * The one spawn policy for the on-demand drainer (#371): every trigger site
 * (run start, run resume, the cron tick's respawn net) goes through this
 * method, so "only when no worker is alive" is decided in exactly one place.
 * A stale read here is harmless -- the drain command's own global lock and
 * the per-user run lock are the real guards against double work; this check
 * only avoids pointlessly forking next to a healthy worker.
 */
final readonly class RecommendationDrainSpawner
{
    public const string DRAIN_COMMAND = 'app:recommendations:drain';

    public function __construct(
        private WorkerPresence $presence,
        private DetachedProcessLauncherInterface $launcher,
    ) {
    }

    public function spawnIfNoWorker(): void
    {
        if ($this->presence->isRecommendationWorkerAlive()) {
            return;
        }

        // --detach makes the spawned process leave the request's session
        // (posix_setsid); the flag exists so an in-process test run of the
        // command does not detach the test runner itself.
        $this->launcher->launch(self::DRAIN_COMMAND, '--detach');
    }
}
