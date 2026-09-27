<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Service\Recommendation\Run\RecommendationRunReport;

final readonly class RecommendationRunStatus
{
    public function __construct(
        public RecommendationRunReport $report,
        public RecommendationForYouSummary $forYou,
        public \DateTimeImmutable $observedAt,
        public ?int $etaSeconds,
    ) {
    }
}
