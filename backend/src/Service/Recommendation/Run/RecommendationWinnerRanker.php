<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

/**
 * Ranks the pooled batch winners by score for the global cut. The sort is stable, so ties keep batch order, which is
 * the candidate loader's recency order.
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
     * The best entries the consolidation call re-scores and dedupes. How many is what the connection's context window
     * holds (RecommendationPromptBuilder::consolidationInputSize()), not a multiple of the final list.
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
