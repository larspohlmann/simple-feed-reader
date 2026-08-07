<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationFeedRow;

/**
 * The result of RecommendationFeedPager::page: a page-worth of rows plus the
 * cursor for the next one, or null if this was the last page.
 */
final readonly class RecommendationFeedPage
{
    /**
     * @param list<RecommendationFeedRow> $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
    ) {
    }
}
