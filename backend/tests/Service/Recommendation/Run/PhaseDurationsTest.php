<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Enum\CallPhase;
use App\Service\Recommendation\Run\PhaseDurations;
use PHPUnit\Framework\TestCase;

final class PhaseDurationsTest extends TestCase
{
    public function testAveragesEachPhaseAcrossRunsWithBatchTimePerBatch(): void
    {
        // Run 1: distill 10s, batch phase 40s over 4 batches (10s/batch),
        // consolidate 30s. Run 2: distill 20s, batch phase 30s over 2 batches
        // (15s/batch), consolidate 50s.
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, CallPhase::Distill, 10.0, 0),
            $this->span(1, CallPhase::Batch, 40.0, 4),
            $this->span(1, CallPhase::Consolidate, 30.0, 0),
            $this->span(2, CallPhase::Distill, 20.0, 0),
            $this->span(2, CallPhase::Batch, 30.0, 2),
            $this->span(2, CallPhase::Consolidate, 50.0, 0),
        ]);

        self::assertNotNull($durations);
        self::assertSame(15.0, $durations->distillSeconds);      // (10 + 20) / 2
        self::assertSame(12.5, $durations->batchSeconds);        // (10 + 15) / 2
        self::assertSame(40.0, $durations->consolidateSeconds);  // (30 + 50) / 2
    }

    public function testPredictedTotalWeightsEachRemainingBatch(): void
    {
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, CallPhase::Distill, 10.0, 0),
            $this->span(1, CallPhase::Batch, 40.0, 4),
            $this->span(1, CallPhase::Consolidate, 30.0, 0),
        ]);

        self::assertNotNull($durations);
        // 10 distill + 3 batches × 10 + 30 consolidate
        self::assertSame(70.0, $durations->predictedTotalSeconds(3));
    }

    public function testARunMissingAPhaseIsIgnored(): void
    {
        // The only run has no consolidate row, so nothing can be averaged.
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, CallPhase::Distill, 10.0, 0),
            $this->span(1, CallPhase::Batch, 40.0, 4),
        ]);

        self::assertNull($durations);
    }

    public function testNoRunsAtAllYieldsNull(): void
    {
        self::assertNull(PhaseDurations::fromCompletedRunSpans([]));
    }

    /**
     * @return array{runId: int, phase: CallPhase, spanSeconds: float, batchCount: int}
     */
    private function span(int $runId, CallPhase $phase, float $spanSeconds, int $batchCount): array
    {
        return ['runId' => $runId, 'phase' => $phase, 'spanSeconds' => $spanSeconds, 'batchCount' => $batchCount];
    }
}
