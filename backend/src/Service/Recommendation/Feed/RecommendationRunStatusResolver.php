<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Feed\Model\RecommendationRunStatusModel;
use App\Service\Recommendation\Profile\ProfileForRun\ProfileForRunInterface;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\RecommendationEtaEstimator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sources the facts every recommendation-run response carries beside the report — the for-you summary, the clock
 * reading, the phase-weighted ETA, whether a pending run waits for its profile and whether a failed run can resume —
 * so no controller gathers them.
 */
final readonly class RecommendationRunStatusResolver
{
    public function __construct(
        private RecommendationForYouSummaryProvider $forYouSummaries,
        private RecommendationEtaEstimator $etaEstimator,
        private ClockInterface $clock,
        private ProfileForRunInterface $profiles,
        private RecommendationRunRepository $runs,
    ) {
    }

    public function forReport(RecommendationRunReportModel $report, User $user): RecommendationRunStatusModel
    {
        return new RecommendationRunStatusModel(
            $report,
            $this->forYouSummaries->forUser($user),
            $this->clock->now(),
            $this->etaEstimator->estimateSeconds($report, $user),
            $this->isWaitingForProfile($report, $user),
            $this->isResumable($report, $user),
        );
    }

    private function isWaitingForProfile(RecommendationRunReportModel $report, User $user): bool
    {
        return RunStatus::Pending->value === $report->status && $this->profiles->isBuildingFor($user);
    }

    private function isResumable(RecommendationRunReportModel $report, User $user): bool
    {
        return RunStatus::Failed->value === $report->status
            && true === $this->runs->findLatestForUser($user)?->isResumable();
    }
}
