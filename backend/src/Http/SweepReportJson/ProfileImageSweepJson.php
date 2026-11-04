<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Social\Model\ProfileImageSweepReportModel;

final readonly class ProfileImageSweepJson implements SweepReportJsonInterface
{
    /** @return array{checked: int, changed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof ProfileImageSweepReportModel) {
            return null;
        }

        return ['checked' => $report->checked, 'changed' => $report->changed];
    }
}
