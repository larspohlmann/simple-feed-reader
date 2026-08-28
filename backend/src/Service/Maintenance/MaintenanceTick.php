<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Recommendation\ForYouSweep;
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
 * /maintenance routes stay for a caller that wants one job only. Keeping an
 * on-demand drainer alive for whatever the sweep leaves active is
 * RecommendationDrainOnTerminateListener's job now (#393), off this class's
 * own termination rather than threaded through here.
 *
 * The due-digests sweep (#636) runs last, after refresh and recommendations,
 * and shares their guard: it also flushes through the same default
 * EntityManager, so it is skipped on the same aborted-refresh tick.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            return new MaintenanceTickReport(
                $refresh->toArray(),
                $this->skippedRecommendations(),
                $this->skippedDigests(),
            );
        }

        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run()->toArray();

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray(), $digests);
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

    /**
     * @return array{considered: int, sent: int, skippedEmpty: int, skipped: string}
     */
    private function skippedDigests(): array
    {
        return [
            'considered' => 0,
            'sent' => 0,
            'skippedEmpty' => 0,
            'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick',
        ];
    }
}
