<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\Model\PrivateFeedSyncReportModel;
use App\Service\Social\PrivateFeedSyncSweep;
use Psr\Clock\ClockInterface;

final readonly class PrivateFeedSyncTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_ACCOUNTS = 1;

    public function __construct(
        private PrivateFeedSyncSweep $privateFeedSyncSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'privateFeedSync';
    }

    public static function priority(): int
    {
        return 20;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->privateFeedSyncSweep->sweep(
            PrivateFeedSyncBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_ACCOUNTS),
        ));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(PrivateFeedSyncReportModel::none());
    }

    private static function reportOf(PrivateFeedSyncReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'accountsChecked' => $report->checked,
            'accountsSkipped' => $report->skipped,
            'added' => $report->added,
            'removed' => $report->removed,
        ]);
    }
}
