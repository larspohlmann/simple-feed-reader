<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Search\Membership\Model\SweepBudgetModel;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Service\Trending\TrendingRebuild;
use Psr\Clock\ClockInterface;

/** The tick's sweeps that recompute views of the entries the earlier sweeps may have changed. */
final readonly class DerivedViewSweeps
{
    /** The membership sweep's own cap inside the tick window: enough for ~50 chunks on Strato. */
    private const int MEMBERSHIP_BUDGET_SECONDS = 10;

    public function __construct(
        private SavedSearchMembershipSweep $membershipSweep,
        private TrendingRebuild $trendingRebuild,
        private ClockInterface $clock,
    ) {
    }

    public function sweepMemberships(\DateTimeImmutable $deadline): SavedSearchMembershipSweepReportModel
    {
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);

        return $this->membershipSweep->sweep($budget);
    }

    public function rebuildTrending(\DateTimeImmutable $deadline): TrendingRebuildReportModel
    {
        return $this->trendingRebuild->rebuildDueBefore($deadline);
    }
}
