<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;

final readonly class RecommendationRunStatusModel
{
    public function __construct(
        public RecommendationRunReportModel $report,
        public RecommendationForYouSummaryModel $forYou,
        public \DateTimeImmutable $observedAt,
        public ?int $etaSeconds,
    ) {
    }
}
