<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

final readonly class RunHistoryOverview
{
    /** @param list<HistoryMonth> $months newest first */
    public function __construct(
        public ?int $totalCostNanoCredits,
        public array $months,
        public ?RunHistoryMonthPage $latest,
    ) {
    }
}
