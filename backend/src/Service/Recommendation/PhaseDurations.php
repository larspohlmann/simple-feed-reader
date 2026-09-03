<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRunLog;

/**
 * The average wall-clock cost of each recommendation phase, learned from an
 * account's recent completed runs (#638), making the time-left estimate
 * phase-aware: distill and consolidate are one heavy provider call each, so a
 * single blended average collapses to zero once the batches finish.
 *
 * `batchSeconds` is wall time per batch, not per batch phase: batch calls fan
 * out concurrently, so the phase span already folds concurrency in, and
 * dividing by the batch count gives one more batch's marginal cost — what
 * {@see predictedTotalSeconds()} multiplies.
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
     * Averages each phase across runs that carry all three phases. A run
     * missing one — partial, or predating always-on logging (#638) —
     * contributes to none, because a total from two phases understates by the
     * third. Null means no run qualified; the caller must then fall back to no
     * estimate, not a fabricated one.
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
