<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;

/**
 * The wire shape every /api/recommendations/runs* action returns: the run
 * report plus the for-you summary. Two different sources kept apart in the
 * services on purpose (see RecommendationForYouSummary), joined only here for
 * the response body.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(RecommendationRunReport $report, RecommendationForYouSummary $summary): array
    {
        return $report->toArray() + [
            'forYou' => [
                'itemCount' => $summary->itemCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    private function __construct()
    {
    }
}
