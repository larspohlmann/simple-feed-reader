<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use Symfony\Component\Clock\ClockInterface;

/**
 * The wire shape every /api/recommendations/runs* action returns: the run
 * report, the run's live `elapsedSeconds` (computed on the server's own clock —
 * the client never subtracts timestamps across machines), the phase-weighted
 * `etaSeconds` (null when there is no estimate yet, #638), and the for-you
 * summary.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(
        RecommendationRunReport $report,
        RecommendationForYouSummary $summary,
        ClockInterface $clock,
        ?int $etaSeconds,
    ): array {
        return $report->toArray() + [
            'elapsedSeconds' => $report->elapsedSecondsAt($clock->now()),
            'etaSeconds' => $etaSeconds,
            'forYou' => [
                // The count of unread surviving picks (#724); the field name
                // stays `itemCount` for wire compatibility.
                'itemCount' => $summary->itemCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
                'newestRunId' => $summary->newestRunId,
            ],
        ];
    }

    private function __construct()
    {
    }
}
