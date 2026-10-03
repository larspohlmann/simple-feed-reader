<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\Feed\Model\RecommendationRunStatusModel;

/**
 * The shape every /api/recommendations/runs* action returns. `elapsedSeconds` comes from the server's clock, so the
 * client never subtracts timestamps across machines; `etaSeconds` and `finishedShare` are null without an estimate.
 * `waitingForProfile`: a pending run waits on a profile run. `resumable`: a failed run that resume() would continue.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(RecommendationRunStatusModel $status): array
    {
        $report = $status->report;
        $summary = $status->forYou;

        return [
            'status' => $report->status,
            'batchesTotal' => $report->batchesTotal,
            'batchesDone' => $report->batchesDone,
            'error' => $report->error,
            'background' => $report->background,
            'waitingForLock' => $report->waitingForLock,
            'waitingForProfile' => $status->waitingForProfile,
            'resumable' => $status->resumable,
            'streamedChars' => $report->streamedChars,
            'firstBatchStarted' => $report->start->firstBatchStarted,
            'elapsedSeconds' => $report->start->elapsedSecondsAt($status->observedAt),
            'etaSeconds' => $status->forecast?->etaSeconds,
            'finishedShare' => $status->forecast?->finishedShare,
            'forYou' => [
                // The count of unread surviving picks; the field name
                // stays `itemCount` for wire compatibility.
                'itemCount' => $summary->itemCount,
                'totalCount' => $summary->totalCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
                'newestRunId' => $summary->newestRunId,
            ],
        ];
    }

    private function __construct()
    {
    }
}
