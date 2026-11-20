<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Run;

use App\Service\Curation\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Curation\Recommendation\Run\Support\OneWinnerPerArticle;

/**
 * Ranks the pooled batch winners by score for the global cut and keeps one per shared article. The sort is stable, so
 * ties keep batch order, which is the candidate loader's recency order.
 */
final readonly class RecommendationWinnerRanker
{
    public function __construct(private RecommendationCandidateLoader $candidateLoader)
    {
    }

    /**
     * @param list<list<array{id: int, score: int, reason: string}>> $batchWinners
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function ranked(int $userId, array $batchWinners): array
    {
        $pool = array_merge(...$batchWinners);

        usort($pool, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return OneWinnerPerArticle::filterWinners(
            $pool,
            $this->candidateLoader->linesForIds($userId, array_column($pool, 'id')),
        );
    }
}
