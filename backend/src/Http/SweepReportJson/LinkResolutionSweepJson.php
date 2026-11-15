<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;

final readonly class LinkResolutionSweepJson implements SweepReportJsonInterface
{
    /** @return array{considered: int, resolved: int, unchanged: int, unresolved: int, deferred: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof LinkResolutionReportModel) {
            return null;
        }

        return [
            'considered' => $report->considered,
            'resolved' => $report->resolved,
            'unchanged' => $report->unchanged,
            'unresolved' => $report->unresolved,
            'deferred' => $report->deferred,
        ];
    }
}
