<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileCallBudgetModel;
use Psr\Clock\ClockInterface;

final readonly class HiddenProfilesTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_CALLS = 1;

    public function __construct(
        private HiddenProfileSwitchSweep $hiddenProfileSwitchSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'hiddenProfiles';
    }

    public static function priority(): int
    {
        return 40;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->hiddenProfileSwitchSweep->sweep(
            ProfileCallBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_CALLS),
        ));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(HiddenProfileSwitchReportModel::none());
    }

    private static function reportOf(HiddenProfileSwitchReportModel $report): SweepReportModel
    {
        return new SweepReportModel(['started' => $report->started, 'finished' => $report->finished]);
    }
}
