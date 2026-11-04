<?php

declare(strict_types=1);

namespace App\Service\Maintenance\MaintenanceSweep;

use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Mail\Digest\SendDueDigests;

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

    public function run(\DateTimeImmutable $deadline): DigestSweepReportModel
    {
        return $this->sendDueDigests->run();
    }

    public function idleReport(): DigestSweepReportModel
    {
        return DigestSweepReportModel::none();
    }
}
