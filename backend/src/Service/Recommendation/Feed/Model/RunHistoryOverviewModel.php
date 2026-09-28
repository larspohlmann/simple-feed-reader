<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

final readonly class RunHistoryOverviewModel
{
    /** @param list<HistoryMonthModel> $months newest first */
    public function __construct(
        public ?int $totalCostNanoCredits,
        public array $months,
        public ?RunHistoryMonthPageModel $latest,
    ) {
    }
}
