<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Outbound\Image\Model\ImageVerificationReportModel;

final readonly class ImageVerificationJson implements SweepReportJsonInterface
{
    /** @return array{measured: int, kept: int, dropped: int, retried: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof ImageVerificationReportModel) {
            return null;
        }

        return [
            'measured' => $report->measured,
            'kept' => $report->kept,
            'dropped' => $report->dropped,
            'retried' => $report->retried,
        ];
    }
}
