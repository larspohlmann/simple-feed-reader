<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;

final readonly class ScoringBatchPacker
{
    /**
     * @param list<ArticleLineModel>          $candidates
     * @param \Closure(ArticleLineModel): int $itemTokens what one article takes in the protocol's request
     *
     * @return list<list<int>> entry ids per request, in candidate order
     */
    public function pack(array $candidates, ScoringBudgetModel $budget, \Closure $itemTokens): array
    {
        $batches = [];
        $current = [];
        $used = 0;

        foreach ($candidates as $candidate) {
            $tokens = $itemTokens($candidate);
            if (
                [] !== $current
                && (
                    \count($current) >= $budget->maxItemsPerRequest
                    || $used + $tokens > $budget->itemTokens()
                )
            ) {
                $batches[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = $candidate->entryId;
            $used += $tokens;
        }

        if ([] !== $current) {
            $batches[] = $current;
        }

        return $batches;
    }
}
