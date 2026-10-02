<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;

/**
 * Each phase's average wall-clock cost over the account's recent completed runs, keyed by CallPhase value. A
 * single-call phase costs its span; the batch phase is per batch: its span already folds in concurrency, so dividing
 * by the batch count gives one more batch's marginal cost.
 */
final readonly class PhaseDurationsModel
{
    /** @param array<string, float> $secondsByPhase */
    private function __construct(public array $secondsByPhase)
    {
    }

    /**
     * Averages only runs that carry exactly the kind's phases: a total from fewer understates, and another kind's run
     * times another engine. Null when no run qualifies; the caller then shows no estimate, never a made-up one.
     *
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int}> $spans
     */
    public static function fromCompletedRunSpans(array $spans, RecommendationEngineKind $engineKind): ?self
    {
        $sums = [];
        $runCount = 0;

        foreach (self::groupByRun($spans) as $phases) {
            $durations = self::runDurations($phases, $engineKind);
            if (null === $durations) {
                continue;
            }

            foreach ($durations as $phase => $seconds) {
                $sums[$phase] = ($sums[$phase] ?? 0.0) + $seconds;
            }
            ++$runCount;
        }

        if (0 === $runCount) {
            return null;
        }

        return new self(array_map(static fn (float $sum): float => $sum / $runCount, $sums));
    }

    public function predictedTotalSeconds(int $batchCount): float
    {
        $total = 0.0;
        foreach ($this->secondsByPhase as $phase => $seconds) {
            $total += CallPhase::Batch->value === $phase ? $batchCount * $seconds : $seconds;
        }

        return $total;
    }

    /**
     * One run's duration per phase, in the kind's order, the batch phase per batch; null when the run does not carry
     * exactly the kind's phases.
     *
     * @param array<string, array{spanSeconds: float, batchCount: int}> $phases
     *
     * @return array<string, float>|null
     */
    private static function runDurations(array $phases, RecommendationEngineKind $engineKind): ?array
    {
        $batch = $phases[CallPhase::Batch->value] ?? null;
        if (null === $batch || $batch['batchCount'] < 1 || !self::carriesExactly($phases, $engineKind)) {
            return null;
        }

        $durations = [];
        foreach ($engineKind->phases() as $phase) {
            $span = $phases[$phase->value];
            $durations[$phase->value] = CallPhase::Batch === $phase
                ? $span['spanSeconds'] / $span['batchCount']
                : $span['spanSeconds'];
        }

        return $durations;
    }

    /** @param array<string, array{spanSeconds: float, batchCount: int}> $phases */
    private static function carriesExactly(array $phases, RecommendationEngineKind $engineKind): bool
    {
        $expected = array_map(static fn (CallPhase $phase): string => $phase->value, $engineKind->phases());
        $carried = array_keys($phases);
        sort($expected);
        sort($carried);

        return $expected === $carried;
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
