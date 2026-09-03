<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;

/**
 * One maintenance tick (#346): refresh all due feeds, then start due
 * recommendation runs and advance each active run one step. Refresh runs
 * first so its work commits before the sweep; both halves are near
 * non-throwing on their own (refresh returns `status: "aborted"` on a DB
 * error, the sweep catches per-run failures internally), so this class needs
 * no try/catch.
 *
 * The halves share the default EntityManager, and an aborted refresh has
 * already closed it (a failed flush rolls back and closes the EM — see
 * RefreshRunner). Sweeping against a closed EM would throw
 * EntityManagerClosed uncaught, so the tick skips the sweep and lets the next
 * tick run with a fresh EM.
 *
 * This is the worker-less install's single cron entry point; the granular
 * /maintenance routes stay for callers wanting one job. Draining whatever the
 * sweep leaves active is now RecommendationDrainOnTerminateListener's job
 * (#393), off this class's termination.
 *
 * The due-digests sweep (#636) runs last and shares the guard: it also
 * flushes through the default EntityManager, so it is skipped on the same
 * aborted-refresh tick.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    private const string ABORTED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

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
            'skipped' => self::ABORTED_REASON,
        ];
    }

    /**
     * @return array{considered: int, sent: int, skippedEmpty: int, skipped: string}
     */
    private function skippedDigests(): array
    {
        return (new DigestSweepReport(0, 0, 0))->toArray() + ['skipped' => self::ABORTED_REASON];
    }
}
