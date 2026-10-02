<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Jev\Support\SystemOneJson;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Support\TokenEstimate;

/**
 * Packs the pool into System One requests by the token estimate. The state is budgeted at its ceiling,
 * `JevStateFactory::STATE_TOKEN_BUDGET`, which the factory never exceeds.
 */
final readonly class JevBatchPacker
{
    /** No documented limit; keeps one request's answer, and what a failed one costs, small. */
    private const int MAX_QUESTIONS_PER_REQUEST = 100;

    /** The request's own framing and the estimate's error. */
    private const int FRAMING_TOKENS = 2_000;

    private const int QUESTION_TOKEN_BUDGET = SystemOneCatalog::CONTEXT_WINDOW_TOKENS - self::FRAMING_TOKENS
        - JevStateFactory::STATE_TOKEN_BUDGET;

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
            $tokens = TokenEstimate::of(SystemOneJson::encode($this->requestFactory->question($candidate)));
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
