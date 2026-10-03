<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;

/**
 * Each phase's average wall-clock cost over the account's recent completed runs, keyed by CallPhase value, plus the
 * median time between calls (pickup, tick waits), so the prediction runs on the clock elapsed counts on. The batch
 * phase is per batch: its span folds in concurrency, so dividing by the batch count gives one more batch's cost.
 */
final readonly class PhaseDurationsModel
{
    /** @param array<string, float> $secondsByPhase */
    private function __construct(public array $secondsByPhase, private float $betweenCallSeconds)
    {
    }

    /**
     * Averages only runs that carry exactly the kind's phases: a total from fewer understates, and another kind's run
     * times another engine. Null when no run qualifies; the caller then shows no estimate, never a made-up one.
     *
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int, runSeconds: float}> $spans
     */
    public static function fromCompletedRunSpans(array $spans, RecommendationEngineKind $engineKind): ?self
    {
        $sums = [];
        $betweenCalls = [];

        foreach (self::groupByRun($spans) as $run) {
            $durations = self::runDurations($run['phases'], $engineKind);
            if (null === $durations) {
                continue;
            }

            foreach ($durations as $phase => $seconds) {
                $sums[$phase] = ($sums[$phase] ?? 0.0) + $seconds;
            }
            $betweenCalls[] = self::betweenCallSeconds($run);
        }

        if ([] === $betweenCalls) {
            return null;
        }
        $runCount = \count($betweenCalls);

        return new self(
            array_map(static fn (float $sum): float => $sum / $runCount, $sums),
            self::median($betweenCalls),
        );
    }

    public function predictedTotalSeconds(int $batchCount): float
    {
        $total = $this->betweenCallSeconds;
        foreach ($this->secondsByPhase as $phase => $seconds) {
            $total += CallPhase::Batch->value === $phase ? $batchCount * $seconds : $seconds;
        }

        return $total;
    }

    /** The finished batches' share of the predicted run, so a long tail phase keeps its weight still ahead. */
    public function finishedShare(int $batchesDone, int $batchCount): float
    {
        $total = $this->predictedTotalSeconds($batchCount);
        if ($total <= 0.0) {
            return 0.0;
        }

        return min(1.0, $batchesDone * ($this->secondsByPhase[CallPhase::Batch->value] ?? 0.0) / $total);
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

    /** @param array{runSeconds: float, phases: array<string, array{spanSeconds: float, batchCount: int}>} $run */
    private static function betweenCallSeconds(array $run): float
    {
        return $run['runSeconds'] - array_sum(array_column($run['phases'], 'spanSeconds'));
    }

    /**
     * A median, not a mean: a run resumed hours after it failed is completed too, and its idle would swamp the rest.
     *
     * @param non-empty-list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
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
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int, runSeconds: float}> $spans
     *
     * @return array<int, array{runSeconds: float, phases: array<string, array{spanSeconds: float, batchCount: int}>}>
     */
    private static function groupByRun(array $spans): array
    {
        $byRun = [];
        foreach ($spans as $span) {
            $byRun[$span['runId']]['runSeconds'] = $span['runSeconds'];
            $byRun[$span['runId']]['phases'][$span['phase']->value] = [
                'spanSeconds' => $span['spanSeconds'],
                'batchCount' => $span['batchCount'],
            ];
        }

        return $byRun;
    }
}
