<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\PhaseDurations;
use PHPUnit\Framework\TestCase;

final class PhaseDurationsTest extends TestCase
{
    public function testAveragesEachPhaseAcrossRunsWithBatchTimePerBatch(): void
    {
        // Run 1: distill 10s, batch phase 40s over 4 batches (10s/batch),
        // consolidate 30s. Run 2: distill 20s, batch phase 30s over 2 batches
        // (15s/batch), consolidate 50s.
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, RecommendationRunLog::PHASE_DISTILL, 10.0, 0),
            $this->span(1, RecommendationRunLog::PHASE_BATCH, 40.0, 4),
            $this->span(1, RecommendationRunLog::PHASE_CONSOLIDATE, 30.0, 0),
            $this->span(2, RecommendationRunLog::PHASE_DISTILL, 20.0, 0),
            $this->span(2, RecommendationRunLog::PHASE_BATCH, 30.0, 2),
            $this->span(2, RecommendationRunLog::PHASE_CONSOLIDATE, 50.0, 0),
        ]);

        self::assertNotNull($durations);
        self::assertSame(15.0, $durations->distillSeconds);      // (10 + 20) / 2
        self::assertSame(12.5, $durations->batchSeconds);        // (10 + 15) / 2
        self::assertSame(40.0, $durations->consolidateSeconds);  // (30 + 50) / 2
    }

    public function testPredictedTotalWeightsEachRemainingBatch(): void
    {
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, RecommendationRunLog::PHASE_DISTILL, 10.0, 0),
            $this->span(1, RecommendationRunLog::PHASE_BATCH, 40.0, 4),
            $this->span(1, RecommendationRunLog::PHASE_CONSOLIDATE, 30.0, 0),
        ]);

        self::assertNotNull($durations);
        // 10 distill + 3 batches × 10 + 30 consolidate
        self::assertSame(70.0, $durations->predictedTotalSeconds(3));
    }

    public function testARunMissingAPhaseIsIgnored(): void
    {
        // The only run has no consolidate row, so nothing can be averaged.
        $durations = PhaseDurations::fromCompletedRunSpans([
            $this->span(1, RecommendationRunLog::PHASE_DISTILL, 10.0, 0),
            $this->span(1, RecommendationRunLog::PHASE_BATCH, 40.0, 4),
        ]);

        self::assertNull($durations);
    }

    public function testNoRunsAtAllYieldsNull(): void
    {
        self::assertNull(PhaseDurations::fromCompletedRunSpans([]));
    }

    /**
     * @return array{runId: int, phase: string, spanSeconds: float, batchCount: int}
     */
    private function span(int $runId, string $phase, float $spanSeconds, int $batchCount): array
    {
        return ['runId' => $runId, 'phase' => $phase, 'spanSeconds' => $spanSeconds, 'batchCount' => $batchCount];
    }
}
