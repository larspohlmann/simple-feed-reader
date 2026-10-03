<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Recommendation\Run\Model\PhaseDurationsModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\RunForecastModel;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use Symfony\Component\Clock\ClockInterface;

/**
 * A live run's forecast: the account's phase history predicts the whole run, minus what has elapsed, and weighs the
 * finished batches against it. Null means no estimate (not in flight, or no completed run to learn from).
 */
final readonly class RecommendationRunForecaster
{
    public function __construct(
        private RecommendationRunTimingRepository $timings,
        private ClockInterface $clock,
    ) {
    }

    public function forecast(RecommendationRunReportModel $report, User $user): ?RunForecastModel
    {
        $plan = $report->plan;
        if (null === $plan || !$report->start->firstBatchStarted || !$this->isInFlight($report)) {
            return null;
        }

        $elapsed = $report->start->elapsedSecondsAt($this->clock->now());
        $durations = PhaseDurationsModel::fromCompletedRunSpans(
            $this->timings->completedRunPhaseSpans($user, $plan->engineKind, RunLogRetention::RUNS),
            $plan->engineKind,
        );
        if (null === $elapsed || null === $durations) {
            return null;
        }

        return new RunForecastModel(
            max(0, (int) round($durations->predictedTotalSeconds($plan->batchCount) - $elapsed)),
            $durations->finishedShare($report->batchesDone, $plan->batchCount),
        );
    }

    private function isInFlight(RecommendationRunReportModel $report): bool
    {
        return RunStatus::tryFrom($report->status)?->isActive() ?? false;
    }
}
