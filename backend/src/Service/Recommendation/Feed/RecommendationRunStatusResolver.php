<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\User;
use App\Service\Recommendation\Feed\Model\RecommendationRunStatusModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\RecommendationEtaEstimator;
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
    public function forReport(RecommendationRunReportModel $report, User $user): RecommendationRunStatusModel
    {
        return new RecommendationRunStatusModel(
            $report,
            $this->forYouSummaries->forUser($user),
            $this->clock->now(),
            $this->etaEstimator->estimateSeconds($report, $user),
        );
    }
}
