<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Model;

/** One scoring request's limits: the model's window, how many articles it may carry, and the reader's share. */
final readonly class ScoringBudgetModel
{
    private const int READER_SHARE_PERCENT = 30;
    private const int MAXIMUM_READER_TOKENS = 10_000;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    /** @param positive-int $maxItemsPerRequest */
    public function __construct(
        public int $contextWindowTokens,
        public int $maxItemsPerRequest,
        public int $readerTokens,
        public int $framingTokens,
    ) {
    }

    /** @param positive-int $maxItemsPerRequest */
    public static function forWindow(int $contextWindowTokens, int $maxItemsPerRequest): self
    {
        return new self(
            $contextWindowTokens,
            $maxItemsPerRequest,
            min(self::MAXIMUM_READER_TOKENS, intdiv($contextWindowTokens * self::READER_SHARE_PERCENT, 100)),
            min(self::FRAMING_TOKENS, intdiv($contextWindowTokens, 2)),
        );
    }

    /** The reader is budgeted at its ceiling, which neither System One's state nor a rerank query exceeds. */
    public function itemTokens(): int
    {
        return $this->contextWindowTokens - $this->framingTokens - $this->readerTokens;
    }
}
