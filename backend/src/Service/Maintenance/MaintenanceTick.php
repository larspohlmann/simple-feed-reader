<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Recommendation\ForYouSweep;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;

/**
 * One maintenance tick (#346): refresh all due feeds, then start due
 * recommendation runs and advance each active run one step. Refresh runs first
 * so its work commits before the sweep, and both halves are near-non-throwing
 * on their own -- refresh returns `status: "aborted"` on a database error, and
 * the sweep catches per-run failures internally -- so this class needs no
 * try/catch of its own.
 *
 * The halves are not fully independent, though: they share the single default
 * EntityManager, and an aborted refresh has already closed it (a failed flush
 * rolls back and closes the EM — see RefreshRunner). Calling the sweep against
 * a closed EM would throw EntityManagerClosed, which nothing here catches, so
 * the tick is guarded to skip the sweep that tick instead. The next tick runs
 * on a fresh request with a fresh EM.
 *
 * This is the worker-less install's single cron entry point; the granular
 * /maintenance routes stay for a caller that wants one job only. It also
 * carries the detached drainer's respawn net (#371): when the sweep leaves
 * runs still active, this tick spawns a drainer if no worker is alive.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private RecommendationDrainSpawner $drainSpawner,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            return new MaintenanceTickReport($refresh->toArray(), $this->skippedRecommendations());
        }

        $recommendations = $this->forYouSweep->sweepOnce();
        if ($recommendations->activeRuns > 0) {
            // The respawn net (#371): a drainer that died leaves its runs to
            // this cron path; once the heartbeat is stale again, the next tick
            // brings a fresh drainer up rather than crawling at one step per
            // minute. Runs just started by this very sweep are covered too.
            $this->drainSpawner->spawnIfNoWorker();
        }

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray());
    }

    /**
     * @return array{startedRuns: int, advancedRuns: int, activeRuns: int, skipped: string}
     */
    private function skippedRecommendations(): array
    {
        return [
            'startedRuns' => 0,
            'advancedRuns' => 0,
            'activeRuns' => 0,
            'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick',
        ];
    }
}
