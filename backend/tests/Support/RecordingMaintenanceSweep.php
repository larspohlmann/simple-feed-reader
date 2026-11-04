<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use App\Service\Maintenance\Model\SweepReportModel;

/** A maintenance sweep that records the deadlines it was run with. */
final class RecordingMaintenanceSweep implements MaintenanceSweepInterface
{
    /** @var list<\DateTimeImmutable> */
    public array $deadlines = [];

    public static function name(): string
    {
        return 'recording';
    }

    public static function priority(): int
    {
        return 0;
    }

    public function run(\DateTimeImmutable $deadline): SweepReportModel
    {
        $this->deadlines[] = $deadline;

        return new SweepReportModel(['ran' => true]);
    }

    public function skippedReport(): SweepReportModel
    {
        return new SweepReportModel(['ran' => false]);
    }
}
