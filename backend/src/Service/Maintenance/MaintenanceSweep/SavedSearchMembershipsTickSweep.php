<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Search\Membership\Model\SweepBudgetModel;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use Psr\Clock\ClockInterface;

final readonly class SavedSearchMembershipsTickSweep implements MaintenanceSweepInterface
{
    /** The membership sweep's own cap inside the tick window: enough for ~50 chunks on Strato. */
    private const int BUDGET_SECONDS = 10;

    public function __construct(
        private SavedSearchMembershipSweep $membershipSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'savedSearchMemberships';
    }

    public static function priority(): int
    {
        return 30;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->membershipSweep->sweep(
            SweepBudgetModel::remainingUntil($deadline, $this->clock->now(), self::BUDGET_SECONDS),
        ));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(new SavedSearchMembershipSweepReportModel(0, 0, 0, false));
    }

    private static function reportOf(SavedSearchMembershipSweepReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'searchesSwept' => $report->searchesSwept,
            'entriesScanned' => $report->entriesScanned,
            'matchesInserted' => $report->matchesInserted,
            'caughtUp' => $report->caughtUp,
        ]);
    }
}
