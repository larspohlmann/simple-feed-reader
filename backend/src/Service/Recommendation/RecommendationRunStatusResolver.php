<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use OpenTelemetry\API\Instrumentation\WithSpan;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sources the three facts every recommendation-run response carries beside the report — the for-you
 * summary, the clock reading and the phase-weighted ETA — so no controller gathers them itself (#638).
 */
final readonly class RecommendationRunStatusResolver
{
    public function __construct(
        private RecommendationForYouSummaryProvider $forYouSummaries,
        private RecommendationEtaEstimator $etaEstimator,
        private ClockInterface $clock,
    ) {
    }

    #[WithSpan]
    public function forReport(RecommendationRunReport $report, User $user): RecommendationRunStatus
    {
        return new RecommendationRunStatus(
            $report,
            $this->forYouSummaries->forUser($user),
            $this->clock->now(),
            $this->etaEstimator->estimateSeconds($report, $user),
        );
    }
}
