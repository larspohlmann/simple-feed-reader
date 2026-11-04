<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;

final readonly class RecommendationsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private ForYouSweep $forYouSweep)
    {
    }

    public static function name(): string
    {
        return 'recommendations';
    }

    public static function priority(): int
    {
        return 100;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->forYouSweep->sweepOnce());
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(new ForYouSweepReportModel(0, 0, 0));
    }

    private static function reportOf(ForYouSweepReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'startedRuns' => $report->startedRuns,
            'advancedRuns' => $report->advancedRuns,
            'activeRuns' => $report->activeRuns,
            'startedProfileRuns' => $report->startedProfileRuns,
            'advancedProfileRuns' => $report->advancedProfileRuns,
            'activeProfileRuns' => $report->activeProfileRuns,
        ]);
    }
}
