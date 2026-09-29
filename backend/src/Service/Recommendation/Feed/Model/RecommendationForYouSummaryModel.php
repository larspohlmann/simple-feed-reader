<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

/**
 * The header's and sidebar's view of the surviving for-you list, which a later failed run leaves untouched; the latest
 * run itself is RecommendationRunReportModel.
 */
final readonly class RecommendationForYouSummaryModel
{
    public function __construct(
        // Unread picks only, the sidebar's own "unread", so the badge drops to zero once every pick is read.
        public int $itemCount,
        // The count of ALL surviving picks, unlike itemCount which is unread-only.
        public int $totalCount,
        public ?\DateTimeImmutable $generatedAt,
        // The run whose time the header shows; the client hides that run's divider by id, not by matching timestamps.
        public ?int $newestRunId = null,
    ) {
    }
}
