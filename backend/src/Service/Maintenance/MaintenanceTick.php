<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Image\ImageVerificationReport;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;
use App\Service\Search\Membership\SweepBudget;
use Psr\Clock\ClockInterface;

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
 *
 * The image-verification sweep (#1109) and the membership sweep (#1116) run
 * alongside the digests sweep, under the same guard: they also flush through
 * the default EntityManager.
 *
 * The tick drains the Loki spool (#1003) last, after refresh and the sweep,
 * independent of the EM guard: the shipper touches no EntityManager.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    /**
     * The request window a worker-less install's cron tick must fit. The halves
     * before the membership sweep carry fixed budgets, so it takes what is left.
     */
    private const int TICK_WINDOW_SECONDS = 25;

    /** The membership sweep's own cap inside that window: enough for ~50 chunks on Strato. */
    private const int MEMBERSHIP_BUDGET_SECONDS = 10;

    private const string ABORTED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
        private ImageVerificationSweep $imageVerificationSweep,
        private SavedSearchMembershipSweep $membershipSweep,
        private LokiSpoolShipper $logSpoolShipper,
        private ClockInterface $clock,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TICK_WINDOW_SECONDS));
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            $recommendations = self::skipped(['startedRuns' => 0, 'advancedRuns' => 0, 'activeRuns' => 0]);
            $digests = self::skipped((new DigestSweepReport(0, 0, 0))->toArray());
            $imageVerification = self::skipped((new ImageVerificationReport(0, 0, 0, 0))->toArray());
            $memberships = self::skipped((new SavedSearchMembershipSweepReport(0, 0, 0, false))->toArray());
        } else {
            $recommendations = $this->forYouSweep->sweepOnce()->toArray();
            $digests = $this->sendDueDigests->run()->toArray();
            $imageVerification = $this->imageVerificationSweep->verifyDue()->toArray();
            $budget = SweepBudget::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);
            $memberships = $this->membershipSweep->sweep($budget)->toArray();
        }
        $logShipping = $this->logSpoolShipper->ship()->toArray();

        return new MaintenanceTickReport(
            $refresh->toArray(),
            $recommendations,
            $digests,
            $imageVerification,
            $memberships,
            $logShipping,
        );
    }

    /**
     * @param array<string, mixed> $emptyReport
     *
     * @return array<string, mixed>
     */
    private static function skipped(array $emptyReport): array
    {
        return $emptyReport + ['skipped' => self::ABORTED_REASON];
    }
}
