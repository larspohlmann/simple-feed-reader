<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The header/sidebar's view of the for-you list: how many entries survive
 * and when the list was last refreshed. Deliberately not part of
 * RecommendationRunReport — that describes the latest run (which may have
 * failed), while this describes the surviving list.
 */
final readonly class RecommendationForYouSummary
{
    public function __construct(
        public int $itemCount,
        public ?\DateTimeImmutable $generatedAt,
    ) {
    }
}
