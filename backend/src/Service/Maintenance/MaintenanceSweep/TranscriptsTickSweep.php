<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Maintenance\Model\SweepReportModel;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;
use App\Service\Transcript\TranscriptSweep;
use Psr\Clock\ClockInterface;

final readonly class TranscriptsTickSweep implements MaintenanceSweepInterface
{
    private const int MAX_FETCHES = 10;

    public function __construct(
        private TranscriptSweep $transcriptSweep,
        private ClockInterface $clock,
    ) {
    }

    public static function name(): string
    {
        return 'transcripts';
    }

    public static function priority(): int
    {
        return 60;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->transcriptSweep->sweep(
            TranscriptBudgetModel::fittingBefore($deadline, $this->clock->now(), self::MAX_FETCHES),
        ));
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(TranscriptSweepReportModel::none());
    }

    private static function reportOf(TranscriptSweepReportModel $report): SweepReportModel
    {
        return new SweepReportModel(['claimed' => $report->claimed, 'filled' => $report->filled]);
    }
}
