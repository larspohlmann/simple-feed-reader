<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Model;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;
use App\Service\Recommendation\Run\Model\PhaseDurationsModel;
use PHPUnit\Framework\TestCase;

final class PhaseDurationsModelTest extends TestCase
{
    public function testAveragesEachPhaseAcrossRunsWithBatchTimePerBatch(): void
    {
        // Run 1: batch phase 40s over 4 batches (10s/batch), consolidate 30s.
        // Run 2: batch phase 30s over 2 batches (15s/batch), consolidate 50s.
        $durations = PhaseDurationsModel::fromCompletedRunSpans([
            $this->span(1, CallPhase::Batch, 40.0, 4, 80.0),
            $this->span(1, CallPhase::Consolidate, 30.0, 0, 80.0),
            $this->span(2, CallPhase::Batch, 30.0, 2, 100.0),
            $this->span(2, CallPhase::Consolidate, 50.0, 0, 100.0),
        ], RecommendationEngineKind::Llm);

        self::assertNotNull($durations);
        self::assertSame(12.5, $durations->secondsByPhase[CallPhase::Batch->value]);       // (10 + 15) / 2
        self::assertSame(40.0, $durations->secondsByPhase[CallPhase::Consolidate->value]); // (30 + 50) / 2
    }

    public function testPredictedTotalWeightsEachRemainingBatch(): void
    {
        $durations = PhaseDurationsModel::fromCompletedRunSpans([
            $this->span(1, CallPhase::Batch, 40.0, 4, 80.0),
            $this->span(1, CallPhase::Consolidate, 30.0, 0, 80.0),
        ], RecommendationEngineKind::Llm);

        self::assertNotNull($durations);
        // 3 batches × 10 + 30 consolidate + 10 between calls
        self::assertSame(70.0, $durations->predictedTotalSeconds(3));
    }

    public function testARunMissingAPhaseIsIgnored(): void
    {
        // The only run has no consolidate row, so nothing can be averaged.
        $durations = PhaseDurationsModel::fromCompletedRunSpans([
            $this->span(1, CallPhase::Batch, 40.0, 4, 50.0),
        ], RecommendationEngineKind::Llm);

        self::assertNull($durations);
    }

    public function testNoRunsAtAllYieldsNull(): void
    {
        self::assertNull(PhaseDurationsModel::fromCompletedRunSpans([], RecommendationEngineKind::Llm));
    }

    /** Run 1 is a Jev run (60 s over 3 batches), run 2 an LLM run: each kind learns from its own runs only. */
    public function testEachKindAveragesOnlyRunsWithExactlyItsPhases(): void
    {
        $spans = [
            $this->span(1, CallPhase::Batch, 60.0, 3, 65.0),
            $this->span(2, CallPhase::Batch, 40.0, 4, 80.0),
            $this->span(2, CallPhase::Consolidate, 30.0, 0, 80.0),
        ];

        $jev = PhaseDurationsModel::fromCompletedRunSpans($spans, RecommendationEngineKind::Jev);
        $llm = PhaseDurationsModel::fromCompletedRunSpans($spans, RecommendationEngineKind::Llm);

        self::assertNotNull($jev);
        self::assertSame(105.0, $jev->predictedTotalSeconds(5));   // 5 × 20 + 5 between calls
        self::assertNotNull($llm);
        self::assertSame(70.0, $llm->predictedTotalSeconds(3));    // 3 × 10 + 30 + 10 between calls
    }

    /**
     * Run 1 spends 35 s between its calls (46 − 11), run 2 spends 24 s (40 − 16): 29.5 s on average, on top of 5
     * batches at 2.7 s.
     */
    public function testAddsTheAverageTimeBetweenCallsToThePrediction(): void
    {
        $durations = PhaseDurationsModel::fromCompletedRunSpans([
            $this->span(1, CallPhase::Batch, 11.0, 5, 46.0),
            $this->span(2, CallPhase::Batch, 16.0, 5, 40.0),
        ], RecommendationEngineKind::Jev);

        self::assertNotNull($durations);
        self::assertSame(43.0, $durations->predictedTotalSeconds(5));
    }

    /**
     * Run 1, resumed after 3 h, spends 10 805 s between calls; three others spend 39, 35 and 37 s. The median, 38 s,
     * keeps the prediction among the normal runs (45–49 s for 5 batches at 2 s); a mean would not.
     */
    public function testOneLongIdleRunDoesNotInflateTheTimeBetweenCalls(): void
    {
        $spans = [];
        foreach ([1 => 10800.0, 2 => 34.0, 3 => 30.0, 4 => 32.0] as $runId => $betweenCalls) {
            $spans[] = $this->span($runId, CallPhase::Batch, 10.0, 5, 15.0 + $betweenCalls);
        }

        $durations = PhaseDurationsModel::fromCompletedRunSpans($spans, RecommendationEngineKind::Jev);

        self::assertNotNull($durations);
        self::assertSame(48.0, $durations->predictedTotalSeconds(5));
    }

    /**
     * @return array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int, runSeconds: float}
     */
    private function span(int $runId, CallPhase $phase, float $spanSeconds, int $batchCount, float $runSeconds): array
    {
        return [
            'runId' => $runId,
            'phase' => $phase,
            'spanSeconds' => $spanSeconds,
            'batchCount' => $batchCount,
            'runSeconds' => $runSeconds,
        ];
    }
}
