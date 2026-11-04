<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Social\Model\HiddenProfileSwitchReportModel;

final readonly class HiddenProfileSwitchJson implements SweepReportJsonInterface
{
    /** @return array{started: int, finished: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof HiddenProfileSwitchReportModel) {
            return null;
        }

        return ['started' => $report->started, 'finished' => $report->finished];
    }
}
