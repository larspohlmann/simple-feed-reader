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
     * @param list<list<array{id: int, score: int, reason: string}>> $batchWinners
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function ranked(array $batchWinners): array
    {
        $pool = array_merge(...$batchWinners);

        usort($pool, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $pool;
    }

    /**
     * The best-ranked entries the consolidation call will re-score, reason, and
     * dedup. How many is not a fixed multiple of the final list: it is what the
     * connection's context window can hold (RecommendationPromptBuilder::
     * consolidationInputSize), so a large-context model recovers more of the
     * good candidates the noisy batch filter under-scored while a small one is
     * not handed a call it cannot answer.
     *
     * @param list<array{id: int, score: int, reason: string}> $ranked
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function cutForConsolidation(array $ranked, int $inputSize): array
    {
        return \array_slice($ranked, 0, $inputSize);
    }
}
