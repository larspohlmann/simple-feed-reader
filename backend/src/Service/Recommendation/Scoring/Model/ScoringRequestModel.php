<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;

/** One request before a protocol words it. */
final readonly class ScoringRequestModel
{
    /** @param non-empty-list<ArticleLineModel> $articles the batch's candidates, in snapshot order */
    public function __construct(
        public string $model,
        public ScoringReaderModel $reader,
        public ScoringBudgetModel $budget,
        public array $articles,
    ) {
    }
}
