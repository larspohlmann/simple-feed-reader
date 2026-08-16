<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One calendar month's worth of the run history card (#409): how many runs
 * fell in it and what they cost, bucketed in the viewer's own timezone by
 * HistoryMonthSummariser.
 *
 * `costNanoCredits` is null when no run in the month reported a price — the
 * same distinction a single row and the all-time total already make. A
 * month total of zero would claim every run in it was free, which is not
 * what an unpriced run means.
 */
final readonly class HistoryMonth
{
    public function __construct(
        public string $month,
        public int $runCount,
        public ?int $costNanoCredits,
    ) {
    }
}
