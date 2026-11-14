<?php

declare(strict_types=1);

namespace App\Http\SweepReportJson;

use App\Service\Content\Transcript\Model\TranscriptSweepReportModel;

final readonly class TranscriptSweepJson implements SweepReportJsonInterface
{
    /** @return array{claimed: int, filled: int}|null */
    public function figures(object $report): ?array
    {
        if (!$report instanceof TranscriptSweepReportModel) {
            return null;
        }

        return ['claimed' => $report->claimed, 'filled' => $report->filled];
    }
}
