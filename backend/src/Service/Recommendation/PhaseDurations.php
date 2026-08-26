<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRunLog;

/**
 * The average wall-clock cost of each recommendation phase, learned from an
 * account's recent completed runs (#638). It is what makes the run's time-left
 * estimate phase-aware: the two tail phases, distill and consolidate, are one
 * heavy provider call each and cost nothing like a batch, so a single blended
 * average — which is what the estimate used before — collapses to zero the
 * moment the batches finish.
 *
 * `batchSeconds` is wall time *per batch*, not per batch phase: a run's batch
 * calls fan out concurrently, so the phase's wall span already folds the
 * concurrency in, and dividing it by the batch count gives the marginal cost
 * of one more batch — exactly what {@see predictedTotalSeconds()} multiplies.
 */
final readonly class PhaseDurations
{
    public function __construct(
        public float $distillSeconds,
        public float $batchSeconds,
        public float $consolidateSeconds,
    ) {
    }

    /**
     * Averages each phase across the runs that carry all three phases. A run
     * that is missing one — a partial run, or one that predates always-on
     * logging (#638) — contributes to none of the averages, because a
     * predicted total built from two of the three phases would understate the
     * run by the third. Null means no run qualified, and the caller must fall
     * back to no estimate rather than a fabricated one.
     *
     * @param list<array{runId: int, phase: string, spanSeconds: float, batchCount: int}> $spans
     */
    public static function fromCompletedRunSpans(array $spans): ?self
    {
        $distillSum = 0.0;
        $batchSum = 0.0;
        $consolidateSum = 0.0;
        $runCount = 0;

        foreach (self::groupByRun($spans) as $phases) {
            $durations = self::runDurations($phases);
            if (null === $durations) {
                continue;
            }

            [$distill, $perBatch, $consolidate] = $durations;
            $distillSum += $distill;
            $batchSum += $perBatch;
            $consolidateSum += $consolidate;
            ++$runCount;
        }

        if (0 === $runCount) {
            return null;
        }

        return new self($distillSum / $runCount, $batchSum / $runCount, $consolidateSum / $runCount);
    }

    public function predictedTotalSeconds(int $batchCount): float
    {
        return $this->distillSeconds + $batchCount * $this->batchSeconds + $this->consolidateSeconds;
    }

    /**
     * One completed run's three phase durations, with the batch phase reduced
     * to seconds per batch, or null when the run is missing a phase and so must
     * not contribute a partial prediction to the averages.
     *
     * @param array<string, array{spanSeconds: float, batchCount: int}> $phases
     *
     * @return array{float, float, float}|null
     */
    private static function runDurations(array $phases): ?array
    {
        $distill = $phases[RecommendationRunLog::PHASE_DISTILL] ?? null;
        $batch = $phases[RecommendationRunLog::PHASE_BATCH] ?? null;
        $consolidate = $phases[RecommendationRunLog::PHASE_CONSOLIDATE] ?? null;
        if (null === $distill || null === $batch || null === $consolidate || $batch['batchCount'] < 1) {
            return null;
        }

        return [$distill['spanSeconds'], $batch['spanSeconds'] / $batch['batchCount'], $consolidate['spanSeconds']];
    }

    /**
     * @param list<array{runId: int, phase: string, spanSeconds: float, batchCount: int}> $spans
     *
     * @return array<int, array<string, array{spanSeconds: float, batchCount: int}>>
     */
    private static function groupByRun(array $spans): array
    {
        $byRun = [];
        foreach ($spans as $span) {
            $byRun[$span['runId']][$span['phase']] = [
                'spanSeconds' => $span['spanSeconds'],
                'batchCount' => $span['batchCount'],
            ];
        }

        return $byRun;
    }
}
