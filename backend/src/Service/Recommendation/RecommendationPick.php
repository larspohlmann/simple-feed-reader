<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One entry the model scored, with the reason it gave. The reason is never
 * validated beyond "is it a non-blank string" — a bad reason is not worth
 * discarding an otherwise-valid pick over. The score is stricter: without a
 * numeric score the pick cannot take part in the cross-batch ranking, so
 * the parser discards it.
 */
final readonly class RecommendationPick
{
    public function __construct(
        public int $entryId,
        public int $score,
        public string $reason,
    ) {
    }
}
