<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;

/**
 * One maintenance tick (#346): refresh all due feeds, then start due
 * recommendation runs and advance each active run one step. Refresh runs first
 * so its work commits before the sweep, and both halves are near-non-throwing
 * on their own -- refresh returns `status: "aborted"` on a database error, and
 * the sweep catches per-run failures internally -- so this class needs no guard
 * of its own: each half's status lives in its own report. This is the
 * worker-less install's single cron entry point; the granular /maintenance
 * routes stay for a caller that wants one job only.
 */
final readonly class MaintenanceTick
{
    private const int REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        $recommendations = $this->forYouSweep->sweepOnce();

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray());
    }
}
