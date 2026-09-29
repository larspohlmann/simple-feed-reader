<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed\Model;

/**
 * One calendar month of the run history, bucketed in the viewer's zone. `costNanoCredits` is null when no run in it
 * reported a price: zero would claim every run was free.
 */
final readonly class HistoryMonthModel
{
    public function __construct(
        public string $month,
        public int $runCount,
        public ?int $costNanoCredits,
    ) {
    }
}
