<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

use App\Repository\RecommendationFeedRow;

final readonly class ForYouFeedPageModel
{
    /** @param list<RecommendationFeedRow> $rows */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public FeedAnnotationVisibilityModel $visibility,
    ) {
    }
}
