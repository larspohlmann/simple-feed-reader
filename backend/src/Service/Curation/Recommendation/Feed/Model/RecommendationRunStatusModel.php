<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Service\Curation\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Curation\Recommendation\Run\Model\RunForecastModel;

final readonly class RecommendationRunStatusModel
{
    public function __construct(
        public RecommendationRunReportModel $report,
        public RecommendationForYouSummaryModel $forYou,
        public \DateTimeImmutable $observedAt,
        public ?RunForecastModel $forecast,
        public bool $waitingForProfile = false,
        public bool $resumable = false,
    ) {
    }
}
