<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Enum\ScoringProtocol;
use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;

final readonly class ScoringBudgetFactory
{
    /** No documented limit; keeps one request's answer, and what a failed one costs, small. */
    private const int SYSTEM_ONE_QUESTIONS_PER_REQUEST = 100;

    private const int STATE_TOKENS = 10_000;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    public function create(ScoringProtocol $protocol): ScoringBudgetModel
    {
        return match ($protocol) {
            ScoringProtocol::SystemOne => new ScoringBudgetModel(
                contextWindowTokens: SystemOneCatalog::CONTEXT_WINDOW_TOKENS,
                maxItemsPerRequest: self::SYSTEM_ONE_QUESTIONS_PER_REQUEST,
                stateTokens: self::STATE_TOKENS,
                framingTokens: self::FRAMING_TOKENS,
            ),
        };
    }
}
