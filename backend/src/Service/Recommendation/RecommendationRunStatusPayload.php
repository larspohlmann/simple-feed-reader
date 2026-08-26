<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use Symfony\Component\Clock\ClockInterface;

/**
 * Assembles the status payload every recommendation-run action returns, so the
 * controller hands one collaborator a report and a user rather than gathering
 * the for-you summary, the clock and the phase-weighted ETA at six call sites
 * (#638). The wire shape itself stays in {@see RecommendationRunStatusJson};
 * this only sources the three inputs that shape does not carry.
 */
final readonly class RecommendationRunStatusPayload
{
    public function __construct(
        private RecommendationForYouSummaryProvider $forYouSummaries,
        private RecommendationEtaEstimator $etaEstimator,
        private ClockInterface $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function forReport(RecommendationRunReport $report, User $user): array
    {
        return RecommendationRunStatusJson::report(
            $report,
            $this->forYouSummaries->forUser($user),
            $this->clock,
            $this->etaEstimator->estimateSeconds($report, $user),
        );
    }
}
