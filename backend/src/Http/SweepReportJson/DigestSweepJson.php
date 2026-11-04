<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Mail\Digest\Model\DigestSweepReportModel;

final readonly class DigestSweepJson implements SweepReportJsonInterface
{
    /** @return array{considered: int, sent: int, skippedEmpty: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof DigestSweepReportModel) {
            return null;
        }

        return ['considered' => $report->considered, 'sent' => $report->sent, 'skippedEmpty' => $report->skippedEmpty];
    }
}
