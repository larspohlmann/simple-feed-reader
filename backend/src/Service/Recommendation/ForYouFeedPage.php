<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationFeedRow;

final readonly class ForYouFeedPage
{
    /** @param list<RecommendationFeedRow> $rows */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public FeedAnnotationVisibility $visibility,
    ) {
    }
}
