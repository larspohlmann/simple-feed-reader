<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

/** One scoring request's limits: the model's window, how many articles it may carry, and what the reader takes. */
final readonly class ScoringBudgetModel
{
    public function __construct(
        public int $contextWindowTokens,
        public int $maxItemsPerRequest,
        public int $stateTokens,
        public int $framingTokens,
    ) {
    }

    /** The state is budgeted at its ceiling, which the state factory never exceeds. */
    public function itemTokens(): int
    {
        return $this->contextWindowTokens - $this->framingTokens - $this->stateTokens;
    }
}
