<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

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
