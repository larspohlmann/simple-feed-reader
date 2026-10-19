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
        public int $totalCount,
        public ?\DateTimeImmutable $generatedAt,
        // The run whose time the header shows; the client hides that run's divider by id, not by matching timestamps.
        public ?int $newestRunId = null,
    ) {
    }
}
