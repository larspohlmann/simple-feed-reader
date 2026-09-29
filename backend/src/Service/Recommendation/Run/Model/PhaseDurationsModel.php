<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;

/**
 * Each phase's average wall-clock cost over the account's recent completed runs; distill and consolidate are one heavy
 * call each. `batchSeconds` is per batch: the phase span already folds in concurrency, so dividing by the batch count
 * gives one more batch's marginal cost.
 */
final readonly class PhaseDurationsModel
{
    public function __construct(
        public float $distillSeconds,
        public float $batchSeconds,
        public float $consolidateSeconds,
    ) {
    }

    /**
     * Averages only runs that carry all three phases: a total from two understates by the third. Null when no run
     * qualifies; the caller then shows no estimate, never a made-up one.
     *
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int}> $spans
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
     * One run's three durations, the batch phase per batch; null when a phase is missing.
     *
     * @param array<string, array{spanSeconds: float, batchCount: int}> $phases
     *
     * @return array{float, float, float}|null
     */
    private static function runDurations(array $phases): ?array
    {
        $distill = $phases[CallPhase::Distill->value] ?? null;
        $batch = $phases[CallPhase::Batch->value] ?? null;
        $consolidate = $phases[CallPhase::Consolidate->value] ?? null;
        if (null === $distill || null === $batch || null === $consolidate || $batch['batchCount'] < 1) {
            return null;
        }

        return [$distill['spanSeconds'], $batch['spanSeconds'] / $batch['batchCount'], $consolidate['spanSeconds']];
    }

    /**
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int}> $spans
     *
     * @return array<int, array<string, array{spanSeconds: float, batchCount: int}>>
     */
    private static function groupByRun(array $spans): array
    {
        $byRun = [];
        foreach ($spans as $span) {
            $byRun[$span['runId']][$span['phase']->value] = [
                'spanSeconds' => $span['spanSeconds'],
                'batchCount' => $span['batchCount'],
            ];
        }

        return $byRun;
    }
}
