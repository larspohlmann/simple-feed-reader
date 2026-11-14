<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface;

/**
 * The one spawn policy for the on-demand drainer: fork only while nobody drives the runs. Its only caller,
 * RecommendationDrainOnTerminateListener, fires once per HTTP request. A stale read is harmless: the drain lock and
 * the per-user run lock are the real guards against double work.
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
        if ($this->presence->isAnybodyDrivingRecommendationRuns()) {
            return;
        }

        // --detach makes the spawned process leave the request's session
        // (posix_setsid); the flag exists so an in-process test run of the
        // command does not detach the test runner itself.
        $this->launcher->launch(self::DRAIN_COMMAND, '--detach');
    }
}
