<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Maintenance\Model\SweepReportModel;

final readonly class DigestsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private SendDueDigests $sendDueDigests)
    {
    }

    public static function name(): string
    {
        return 'digests';
    }

    public static function priority(): int
    {
        return 90;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        return self::reportOf($this->sendDueDigests->run());
    }

    public function skippedReport(): SweepReportModel
    {
        return self::reportOf(new DigestSweepReportModel(0, 0, 0));
    }

    private static function reportOf(DigestSweepReportModel $report): SweepReportModel
    {
        return new SweepReportModel([
            'considered' => $report->considered,
            'sent' => $report->sent,
            'skippedEmpty' => $report->skippedEmpty,
        ]);
    }
}
