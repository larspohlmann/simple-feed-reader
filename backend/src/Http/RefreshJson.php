<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Refresh\TrackedRefreshReport;

/**
 * The refresh endpoint's response.
 *
 * `progress` is the run — the one figure a client renders, and the reason no client
 * has to reconcile anything. The counters beside it describe the slice that just
 * landed and name their own scope.
 *
 * There is deliberately no `total`. It was this slice's batch size, capped by
 * RefreshRunner::BATCH_LIMIT, sitting next to a run-wide `remaining` and inviting the
 * division that produced #721. RefreshReport still carries it for the worker's log,
 * which is a different audience with a different question.
 */
final class RefreshJson
{
    /**
     * @return array{status: string, progress: array{done: int, total: int}, fetched: int,
     *     notModified: int, failed: int, throttled: int, skippedForBudget: int,
     *     remaining: int, pruned: int}
     */
    public static function slice(TrackedRefreshReport $tracked): array
    {
        $report = $tracked->report;

        return [
            'status' => $report->status,
            'progress' => $tracked->progress->toArray(),
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
