<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Refresh\RefreshReport;

/** A whole refresh run, `total` included, as the maintenance endpoints report it. */
final class RefreshReportJson
{
    /**
     * @return array{status: string, total: int, fetched: int, notModified: int, failed: int, throttled: int,
     *     skippedForBudget: int, remaining: int, pruned: int}
     */
    public static function report(RefreshReport $report): array
    {
        return [
            'status' => $report->status,
            'total' => $report->total,
            'fetched' => $report->fetched,
            'notModified' => $report->notModified,
            'failed' => $report->failed,
            'throttled' => $report->throttled,
            'skippedForBudget' => $report->skippedForBudget,
            'remaining' => $report->remaining,
            'pruned' => $report->pruned,
        ];
    }
}
