<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Refresh\Model\TrackedRefreshReportModel;

/**
 * The refresh response. `progress` covers the whole run and is the one figure a client renders; the counters are
 * this slice's. No `total`: a slice's batch size beside a run-wide `remaining` invites a wrong division (#721).
 */
final class RefreshJson
{
    /**
     * @return array{status: string, progress: array{done: int, total: int}, fetched: int,
     *     notModified: int, failed: int, throttled: int, skippedForBudget: int,
     *     remaining: int, pruned: int}
     */
    public static function slice(TrackedRefreshReportModel $tracked): array
    {
        $report = $tracked->report;

        return [
            'status' => $report->status,
            'progress' => ['done' => $tracked->progress->done, 'total' => $tracked->progress->total],
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
