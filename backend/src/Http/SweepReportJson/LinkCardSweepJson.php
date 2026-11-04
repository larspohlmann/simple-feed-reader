<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\LinkCard\Model\LinkCardSweepReportModel;

final readonly class LinkCardSweepJson implements SweepReportJsonInterface
{
    /** @return array{considered: int, completed: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof LinkCardSweepReportModel) {
            return null;
        }

        return ['considered' => $report->considered, 'completed' => $report->completed];
    }
}
