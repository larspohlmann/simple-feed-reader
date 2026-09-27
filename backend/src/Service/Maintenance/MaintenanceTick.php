<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Image\ImageVerificationSweep;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use Psr\Clock\ClockInterface;

/**
 * Refresh first, then the sweeps, then the log spool. An aborted refresh closed the shared
 * EntityManager, so the sweeps must not run that tick; the spool touches no EntityManager and ships anyway.
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
        $sweeps = $refresh->isAborted() ? MaintenanceSweeps::skippedAfterAbortedRefresh() : $this->sweep($deadline);

        return new MaintenanceTickReport($refresh, $sweeps, $this->logSpoolShipper->ship());
    }

    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweeps
    {
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run();
        $imageVerification = $this->imageVerificationSweep->verifyDue();
        $budget = SweepBudget::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);

        return new MaintenanceSweeps(
            $recommendations,
            $digests,
            $imageVerification,
            $this->membershipSweep->sweep($budget),
        );
    }
}
