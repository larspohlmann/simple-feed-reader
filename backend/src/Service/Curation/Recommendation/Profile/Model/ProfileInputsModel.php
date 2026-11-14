<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;

final readonly class ProfileInputsModel
{
    /**
     * @param list<string> $savedSearchTerms as the prompt shows them, newest saved first
     */
    public function __construct(
        public RecommendationHistoryModel $history,
        public array $savedSearchTerms,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->history->isEmpty() && [] === $this->savedSearchTerms;
    }
}
