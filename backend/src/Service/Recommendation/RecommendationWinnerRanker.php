<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Ranks the pooled batch winners for the global cut: comparing entries
 * across batches, which the merge model used to do implicitly, done in code
 * on the scores the batches produced against a shared rubric. Pure
 * computation, no collaborators. PHP's sort has been stable since 8.0, so
 * tied scores keep flattening order — batch order, which is snapshot order,
 * which is the candidate loader's recency order.
 */
final readonly class RecommendationWinnerRanker
{
    /**
     * Twice the final list size goes to the dedup call, so an entry dropped
     * as a duplicate backfills from a line the dedup call has also checked.
     */
    private const int DEDUP_INPUT_FACTOR = 2;

    /**
     * @param list<list<array{id: int, score?: int, reason: string}>> $batchWinners
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function ranked(array $batchWinners): array
    {
        $pool = [];
        foreach ($batchWinners as $batch) {
            foreach ($batch as $winner) {
                // A winner recorded before scores existed (a run in flight
                // across the deploy) reads as 0: it sorts last, the run
                // still completes, and the next run self-heals.
                $pool[] = [
                    'id' => $winner['id'],
                    'score' => $winner['score'] ?? 0,
                    'reason' => $winner['reason'],
                ];
            }
        }

        usort($pool, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $pool;
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function cutForDedup(array $ranked, int $picksLimit): array
    {
        return \array_slice($ranked, 0, self::DEDUP_INPUT_FACTOR * $picksLimit);
    }
}
