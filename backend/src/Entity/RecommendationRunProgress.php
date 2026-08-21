<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A snapshot of a recommendation run's derived progress, computed from the
 * frozen candidate batch plan, the number of batch calls completed so far,
 * and the retry count of the call in progress.

 * Everything the run derives rather than stores lives here, so a caller asks
 * one object what state the run is in instead of the entity growing a query
 * method per question. This is a plain value object — it holds no persistence mapping and
 * is rebuilt on every call to {@see RecommendationRun::progress()}.
 */
final readonly class RecommendationRunProgress
{
    public function __construct(
        public int $batchesDone,
        public ?int $batchesTotal,
        public bool $needsDedup,
        public bool $isDedupPhase,
        public bool $distillPending,
        public bool $isConsolidationPhase,
        public bool $allBatchCallsDone,
        public int $nextBatchIndex,
        public bool $attemptsExhausted,
    ) {
    }

    /**
     * @param list<list<int>>|null $candidateBatches null while the run is
     *     still pending, i.e. before {@see RecommendationRun::snapshot()}
     * @param int                  $attempts         unusable replies for the call now in progress
     * @param bool                 $distilled        whether {@see RecommendationRun::recordProfile()} has run
     */
    public static function forBatchPlan(
        ?array $candidateBatches,
        int $batchesDone,
        int $attempts,
        bool $distilled,
    ): self {
        $batchCount = $candidateBatches === null ? 0 : count($candidateBatches);
        $hasPlan = $candidateBatches !== null && $batchCount > 0;
        $needsDedup = $batchCount > 1;
        $allBatchCallsDone = $batchesDone === $batchCount;

        return new self(
            batchesDone: $batchesDone,
            batchesTotal: $hasPlan ? $batchCount + 2 : null,
            needsDedup: $needsDedup,
            isDedupPhase: $allBatchCallsDone && $needsDedup,
            distillPending: $hasPlan && !$distilled,
            isConsolidationPhase: $hasPlan && $distilled && $allBatchCallsDone,
            allBatchCallsDone: $allBatchCallsDone,
            nextBatchIndex: $batchesDone,
            attemptsExhausted: $attempts >= RecommendationRun::MAX_ATTEMPTS,
        );
    }
}
