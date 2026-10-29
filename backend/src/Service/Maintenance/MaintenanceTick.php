<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Search\Membership\Model\SweepBudgetModel;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Social\SocialMaintenance;
use Psr\Clock\ClockInterface;

/**
 * Refresh first, then the sweeps, then the log spool. An aborted refresh closed the shared
 * EntityManager, so the sweeps must not run that tick; the spool touches no EntityManager and ships anyway.
 */
final readonly class MaintenanceTick
{
    public const int REFRESH_BUDGET_SECONDS = 20;

    /** The membership sweep's own cap inside the tick window: enough for ~50 chunks on Strato. */
    private const int MEMBERSHIP_BUDGET_SECONDS = 10;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
        private FollowUpFetchSweeps $followUpFetchSweeps,
        private SocialMaintenance $socialMaintenance,
        private SavedSearchMembershipSweep $membershipSweep,
        private LokiSpoolShipper $logSpoolShipper,
        private ClockInterface $clock,
        private int $tickWindowSeconds,
    ) {
    }

    public function run(): MaintenanceTickReportModel
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', $this->tickWindowSeconds));
        $refresh = $this->refreshRunner->run(RefreshRequestModel::allDue(self::REFRESH_BUDGET_SECONDS));
        $sweeps = $refresh->isAborted()
            ? MaintenanceSweepsModel::skippedAfterAbortedRefresh()
            : $this->sweep($deadline);

        return new MaintenanceTickReportModel($refresh, $sweeps, $this->logSpoolShipper->ship());
    }

    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweepsModel
    {
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run();
        $followUpFetches = $this->followUpFetchSweeps->sweep($deadline);
        $profileImages = $this->socialMaintenance->refreshProfileImages($deadline);
        $hiddenProfiles = $this->socialMaintenance->switchHiddenProfiles($deadline);
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);
        $memberships = $this->membershipSweep->sweep($budget);

        return new MaintenanceSweepsModel(
            $recommendations,
            $digests,
            $followUpFetches,
            $profileImages,
            $hiddenProfiles,
            $this->socialMaintenance->syncPrivateFeeds($deadline),
            $memberships,
        );
    }
}
