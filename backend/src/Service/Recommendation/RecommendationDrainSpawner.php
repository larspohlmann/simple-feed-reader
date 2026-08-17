<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Worker\WorkerPresence;

/**
 * The one spawn policy for the on-demand drainer (#371): every trigger site
 * goes through this method, so "only when nobody is already driving the
 * runs" is decided in exactly one place. Since #393 the only caller is
 * RecommendationDrainOnTerminateListener, which fires once per HTTP request
 * and on no console exit at all -- that is what keeps a single web request or
 * cron tick from forking more than once, with no memory needed here. Why the
 * console half was dropped is written out on the listener.
 *
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
        // Either kind of worker counts: forking beside a live drainer is as
        // pointless as forking beside the persistent worker, and the new
        // process would only lose the drain lock.
        if ($this->presence->isAnybodyDrivingRecommendationRuns()) {
            return;
        }

        // --detach makes the spawned process leave the request's session
        // (posix_setsid); the flag exists so an in-process test run of the
        // command does not detach the test runner itself.
        $this->launcher->launch(self::DRAIN_COMMAND, '--detach');
    }
}
