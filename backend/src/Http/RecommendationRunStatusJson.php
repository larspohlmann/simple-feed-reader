<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use Symfony\Component\Clock\ClockInterface;

/**
 * The wire shape every /api/recommendations/runs* action returns: the run
 * report, the run's live `elapsedSeconds` (computed here so it stays on the
 * server's own clock — the client never subtracts timestamps across machines),
 * and the for-you summary.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(
        RecommendationRunReport $report,
        RecommendationForYouSummary $summary,
        ClockInterface $clock,
    ): array {
        return $report->toArray() + [
            'elapsedSeconds' => self::elapsedSeconds($report, $clock),
            'forYou' => [
                'itemCount' => $summary->itemCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    private static function elapsedSeconds(RecommendationRunReport $report, ClockInterface $clock): ?int
    {
        if (null === $report->startedAt) {
            return null;
        }

        return max(0, $clock->now()->getTimestamp() - $report->startedAt->getTimestamp());
    }

    private function __construct()
    {
    }
}
