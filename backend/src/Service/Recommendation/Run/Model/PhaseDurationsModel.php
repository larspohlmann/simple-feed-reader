<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;

/**
 * Each phase's average wall-clock cost over the account's recent completed runs. Distill and consolidate are one heavy
 * call each; a kind that skips one gets 0 s for it. `batchSeconds` is per batch: the phase span already folds in
 * concurrency, so dividing by the batch count gives one more batch's marginal cost.
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
     * Averages only runs that carry exactly the kind's phases: a total from fewer understates, and another kind's run
     * times another engine. Null when no run qualifies; the caller then shows no estimate, never a made-up one.
     *
     * @param list<array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int}> $spans
     */
    public static function fromCompletedRunSpans(array $spans, RecommendationEngineKind $engineKind): ?self
    {
        $distillSum = 0.0;
        $batchSum = 0.0;
        $consolidateSum = 0.0;
        $runCount = 0;

        foreach (self::groupByRun($spans) as $phases) {
            $durations = self::runDurations($phases, $engineKind);
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
     * One run's three durations, the batch phase per batch, a phase the kind skips at 0 s; null when the run does not
     * carry exactly the kind's phases.
     *
     * @param array<string, array{spanSeconds: float, batchCount: int}> $phases
     *
     * @return array{float, float, float}|null
     */
    private static function runDurations(array $phases, RecommendationEngineKind $engineKind): ?array
    {
        $batch = $phases[CallPhase::Batch->value] ?? null;
        if (null === $batch || $batch['batchCount'] < 1 || !self::carriesExactly($phases, $engineKind)) {
            return null;
        }

        return [
            $phases[CallPhase::Distill->value]['spanSeconds'] ?? 0.0,
            $batch['spanSeconds'] / $batch['batchCount'],
            $phases[CallPhase::Consolidate->value]['spanSeconds'] ?? 0.0,
        ];
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
