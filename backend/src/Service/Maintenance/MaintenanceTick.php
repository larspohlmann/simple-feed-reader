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
 * The image-verification sweep (#1109) runs alongside the digests sweep,
 * under the same guard: it also flushes through the default EntityManager.
 *
 * The membership sweep (#1116) runs under the same guard, after the image
 * sweep.
 *
 * The tick drains the Loki spool (#1003) last, after refresh and the sweep,
 * independent of the EM guard: the shipper touches no EntityManager.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    /** Inside the 20 s tick, after the refresh: enough for ~50 chunks on Strato. */
    private const int MEMBERSHIP_BUDGET_SECONDS = 10;

    private const string ABORTED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
        private ImageVerificationSweep $imageVerificationSweep,
        private SavedSearchMembershipSweep $membershipSweep,
        private LokiSpoolShipper $logSpoolShipper,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            $recommendations = $this->skippedRecommendations();
            $digests = $this->skippedDigests();
            $imageVerification = $this->skippedImageVerification();
            $memberships = $this->skippedMemberships();
        } else {
            $recommendations = $this->forYouSweep->sweepOnce()->toArray();
            $digests = $this->sendDueDigests->run()->toArray();
            $imageVerification = $this->imageVerificationSweep->verifyDue()->toArray();
            $budget = SweepBudget::seconds(self::MEMBERSHIP_BUDGET_SECONDS);
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

    /**
     * @return array{measured: int, kept: int, dropped: int, retried: int, skipped: string}
     */
    private function skippedImageVerification(): array
    {
        return (new ImageVerificationReport(0, 0, 0, 0))->toArray() + ['skipped' => self::ABORTED_REASON];
    }

    /**
     * @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool, skipped: string}
     */
    private function skippedMemberships(): array
    {
        return (new SavedSearchMembershipSweepReport(0, 0, 0, false))->toArray() + ['skipped' => self::ABORTED_REASON];
    }
}
