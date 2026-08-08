<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A snapshot of a recommendation run's derived progress, computed from the
 * frozen candidate batch plan and the number of batch calls completed so
 * far. This is a plain value object — it holds no persistence mapping and
 * is rebuilt on every call to {@see RecommendationRun::progress()}.
 */
final readonly class RecommendationRunProgress
{
    public function __construct(
        public int $batchesDone,
        public ?int $batchesTotal,
        public bool $needsDedup,
        public bool $isDedupPhase,
        public bool $allBatchCallsDone,
        public int $nextBatchIndex,
    ) {
    }

    /**
     * @param list<list<int>>|null $candidateBatches null while the run is
     *     still pending, i.e. before {@see RecommendationRun::snapshot()}
     */
    public static function forBatchPlan(?array $candidateBatches, int $batchesDone): self
    {
        $batchCount = $candidateBatches === null ? 0 : count($candidateBatches);
        $needsDedup = $batchCount > 1;
        $allBatchCallsDone = $batchesDone === $batchCount;

        return new self(
            batchesDone: $batchesDone,
            batchesTotal: self::batchesTotal($candidateBatches, $batchCount),
            needsDedup: $needsDedup,
            isDedupPhase: $allBatchCallsDone && $needsDedup,
            allBatchCallsDone: $allBatchCallsDone,
            nextBatchIndex: $batchesDone,
        );
    }

    /**
     * The dedup call counts as one extra batch for progress purposes.
     *
     * @param list<list<int>>|null $candidateBatches
     */
    private static function batchesTotal(?array $candidateBatches, int $batchCount): ?int
    {
        if ($candidateBatches === null) {
            return null;
        }

        return $batchCount + ($batchCount > 1 ? 1 : 0);
    }
}
