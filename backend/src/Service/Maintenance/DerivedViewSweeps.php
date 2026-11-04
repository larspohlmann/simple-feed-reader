<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Maintenance\Model\DerivedViewsReportModel;
use App\Service\Search\Membership\Model\SweepBudgetModel;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Service\Trending\TrendingRebuild;
use Psr\Clock\ClockInterface;

/** The tick's last sweeps: they recompute views of the entries the earlier sweeps may have changed. */
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

    public function sweep(\DateTimeImmutable $deadline): DerivedViewsReportModel
    {
        $budget = SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);
        $memberships = $this->membershipSweep->sweep($budget);

        return new DerivedViewsReportModel($memberships, $this->trendingWhileTimeIsLeft($deadline));
    }

    private function trendingWhileTimeIsLeft(\DateTimeImmutable $deadline): TrendingRebuildReportModel
    {
        if ($this->clock->now() >= $deadline) {
            return TrendingRebuildReportModel::none();
        }

        return $this->trendingRebuild->rebuildDue();
    }
}
