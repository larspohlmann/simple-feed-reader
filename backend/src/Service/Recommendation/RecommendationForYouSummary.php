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
        // The newest completed run's id — the run whose generation time the
        // header shows. The client suppresses that one run's divider by id
        // rather than by matching timestamps across two serializers (#348).
        public ?int $newestRunId = null,
    ) {
    }
}
