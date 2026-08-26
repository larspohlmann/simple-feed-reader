<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunTimingRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The seconds a live recommendation run still needs, weighted by phase (#638).
 *
 * The estimate this replaced blended every phase into one average and assumed
 * each remaining unit cost the same, so the two tail phases — distill and
 * consolidate, one heavy provider call each — read as a single batch's worth
 * of time and the number collapsed to zero the moment the batches finished.
 * This predicts the whole run from the account's own phase history and
 * subtracts what has already elapsed, so the tail keeps an honest figure.
 *
 * Null means no estimate: the run is not in flight, or the account has no
 * completed run to learn from yet. A null must surface as a blank, never as a
 * fabricated number.
 */
final readonly class RecommendationEtaEstimator
{
    /**
     * The two phases beyond the batch calls — distill before them and
     * consolidate after — that {@see RecommendationRunProgress} adds to the
     * batch count to form `batchesTotal`.
     */
    private const int TAIL_PHASE_COUNT = 2;

    public function __construct(
        private RecommendationRunTimingRepository $timings,
        private ClockInterface $clock,
    ) {
    }

    public function estimateSeconds(RecommendationRunReport $report, User $user): ?int
    {
        if (null === $report->batchesTotal || !$this->isInFlight($report)) {
            return null;
        }

        $elapsed = $report->elapsedSecondsAt($this->clock->now());
        $durations = PhaseDurations::fromCompletedRunSpans(
            $this->timings->completedRunPhaseSpans($user, RunLogRetention::RUNS),
        );
        if (null === $elapsed || null === $durations) {
            return null;
        }

        $batchCount = $report->batchesTotal - self::TAIL_PHASE_COUNT;

        return max(0, (int) round($durations->predictedTotalSeconds($batchCount) - $elapsed));
    }

    private function isInFlight(RecommendationRunReport $report): bool
    {
        return \in_array(
            $report->status,
            [RecommendationRun::STATUS_RUNNING, RecommendationRun::STATUS_PENDING],
            true,
        );
    }
}
