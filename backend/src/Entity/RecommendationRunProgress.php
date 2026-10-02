<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;

/**
 * What a run's state implies, derived from the frozen batch plan, the batches done and the current call's attempts.
 * A plain value, rebuilt by every RecommendationRun::getProgress() call.
 */
final readonly class RecommendationRunProgress
{
    public function __construct(
        public int $batchesDone,
        public ?int $batchCount,
        public ?int $batchesTotal,
        public bool $distillPending,
        public bool $isConsolidationPhase,
        public bool $allBatchCallsDone,
        public int $nextBatchIndex,
        public bool $attemptsExhausted,
    ) {
    }

    /**
     * @param list<list<int>>|null $candidateBatches null before RecommendationRun::snapshot()
     * @param int                  $attempts         unusable replies for the call now in progress
     */
    public static function forBatchPlan(
        ?array $candidateBatches,
        int $batchesDone,
        int $attempts,
        bool $distilled,
        RecommendationEngineKind $engineKind,
    ): self {
        $batchCount = $candidateBatches === null ? 0 : count($candidateBatches);
        $hasPlan = $candidateBatches !== null && $batchCount > 0;
        $allBatchCallsDone = $batchesDone === $batchCount;
        $distillationDone = $distilled || !$engineKind->runs(CallPhase::Distill);

        return new self(
            batchesDone: $batchesDone,
            batchCount: $hasPlan ? $batchCount : null,
            batchesTotal: $hasPlan ? $batchCount + $engineKind->singleCallPhaseCount() : null,
            distillPending: $hasPlan && !$distillationDone,
            isConsolidationPhase: $hasPlan && $distillationDone && $allBatchCallsDone
                && $engineKind->runs(CallPhase::Consolidate),
            allBatchCallsDone: $allBatchCallsDone,
            nextBatchIndex: $batchesDone,
            attemptsExhausted: $attempts >= RecommendationRun::MAX_ATTEMPTS,
        );
    }
}
