<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One entry the model chose, with the reason it gave. The reason is never
 * validated beyond "is it a non-blank string" — a bad reason is not worth
 * discarding an otherwise-valid pick over.
 */
final readonly class RecommendationPick
{
    public function __construct(
        public int $entryId,
        public string $reason,
    ) {
    }
}
