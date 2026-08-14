<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Worker\WorkerPresence;

/**
 * The one spawn policy for the on-demand drainer (#371): every trigger site
 * (run start, run resume, the cron tick's respawn net) goes through this
 * method, so "only when nobody is already driving the runs" is decided in
 * exactly one place.
 * A stale read here is harmless -- the drain command's own global lock and
 * the per-user run lock are the real guards against double work; this check
 * only avoids pointlessly forking next to a healthy worker.
 *
 * Deliberately not `readonly`: one launch per process is enough, and the
 * spawner remembers that it made it. Without that memory a single maintenance
 * tick forks once per due run it starts (RecommendationRunStarter::start())
 * plus once for its own respawn net, so five due runs cost six full Symfony
 * boots on a shared host of which exactly one wins the drain lock. The same
 * memory caps a user clicking start repeatedly inside one request. The flag is
 * process-scoped, which is the right scope: every caller is a short-lived web
 * request or cron tick, and the one long-lived process that holds this service
 * -- the Docker worker -- keeps its own heartbeat fresh and so never launches
 * at all.
 */
final class RecommendationDrainSpawner
{
    public const string DRAIN_COMMAND = 'app:recommendations:drain';

    private bool $launched = false;

    public function __construct(
        private readonly WorkerPresence $presence,
        private readonly DetachedProcessLauncherInterface $launcher,
    ) {
    }

    public function spawnIfNoWorker(): void
    {
        // Checked before the heartbeat read, so a repeat call costs no query.
        if ($this->launched) {
            return;
        }

        // Either kind of worker counts: forking beside a live drainer is as
        // pointless as forking beside the persistent worker, and the new
        // process would only lose the drain lock.
        if ($this->presence->isAnybodyDrivingRecommendationRuns()) {
            return;
        }

        $this->launched = true;

        // --detach makes the spawned process leave the request's session
        // (posix_setsid); the flag exists so an in-process test run of the
        // command does not detach the test runner itself.
        $this->launcher->launch(self::DRAIN_COMMAND, '--detach');
    }
}
