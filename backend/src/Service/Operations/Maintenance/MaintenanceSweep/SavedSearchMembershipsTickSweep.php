<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Library\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SavedSearchMembershipSweep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'savedSearchMemberships', priority: 30)]
final readonly class SavedSearchMembershipsTickSweep implements MaintenanceSweepInterface
{
    /** The membership sweep's own cap inside the tick window: enough for ~50 chunks on Strato. */
    private const int BUDGET_SECONDS = 10;

    public function __construct(
        private SavedSearchMembershipSweep $membershipSweep,
        private ClockInterface $clock,
    ) {
    }

    public function run(\DateTimeImmutable $deadline): SavedSearchMembershipSweepReportModel
    {
        return $this->membershipSweep->sweep(
            SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::BUDGET_SECONDS),
        );
    }

    public function idleReport(): SavedSearchMembershipSweepReportModel
    {
        return SavedSearchMembershipSweepReportModel::none();
    }
}
