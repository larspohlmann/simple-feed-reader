<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Jev\Support\JevTokenEstimate;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;

/**
 * Packs the pool into System One requests by the token estimate. The state is budgeted at its ceiling, not its size
 * now: every wave rebuilds it.
 */
final readonly class JevBatchPacker
{
    /** No documented limit; keeps one request's answer, and what a failed one costs, small. */
    private const int MAX_QUESTIONS_PER_REQUEST = 100;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    private const int STATE_TOKENS = 4_000;

    private const int QUESTION_TOKEN_BUDGET = SystemOneCatalog::CONTEXT_WINDOW_TOKENS - self::FRAMING_TOKENS
        - self::STATE_TOKENS;

    public function __construct(private SystemOneRequestFactory $requestFactory)
    {
    }

    /**
     * @param list<ArticleLineModel> $candidates
     *
     * @return list<list<int>>
     */
    public function pack(array $candidates): array
    {
        $batches = [];
        $current = [];
        $used = 0;

        foreach ($candidates as $candidate) {
            $tokens = JevTokenEstimate::ofJson($this->requestFactory->question($candidate));
            if (
                [] !== $current
                && (
                    \count($current) >= self::MAX_QUESTIONS_PER_REQUEST
                    || $used + $tokens > self::QUESTION_TOKEN_BUDGET
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
