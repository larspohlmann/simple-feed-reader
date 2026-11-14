<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Outbound\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Outbound\Mail\Digest\SendDueDigests;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'digests', priority: 90)]
final readonly class DigestsTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private SendDueDigests $sendDueDigests)
    {
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
