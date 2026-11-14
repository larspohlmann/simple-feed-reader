<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Recommendation\Feed\Model\HistoryMonthModel;

/**
 * Folds the spend timeline into one HistoryMonthModel per calendar month, newest first. Why this happens in PHP:
 * RecommendationRunHistoryRepository::spendTimeline().
 */
final readonly class HistoryMonthSummariser
{
    /**
     * @param list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}> $spendTimeline
     *
     * @return list<HistoryMonthModel> newest month first
     */
    public function summarise(array $spendTimeline, ViewerTimeZoneModel $viewer): array
    {
        $totals = [];

        foreach ($spendTimeline as $row) {
            // Correct only because the hydrated value carries UTC (Kernel::boot() pins the default zone, see
            // KernelTimezoneTest). Lose the pin and these month headers drift from their rows by the host offset.
            $month = $row['createdAt']->setTimezone($viewer->zone)->format('Y-m');
            $totals[$month] = $this->foldRowInto($totals[$month] ?? null, $row['costNanoCredits']);
        }

        krsort($totals);

        return array_map(
            static fn (string $month, array $total): HistoryMonthModel => new HistoryMonthModel(
                $month,
                $total['runCount'],
                $total['costNanoCredits'],
            ),
            array_keys($totals),
            array_values($totals),
        );
    }

    /**
     * The count grows on every row; the cost stays null until a priced row arrives (see HistoryMonthModel).
     *
     * @param ?array{runCount: int, costNanoCredits: ?int} $runningTotal
     *
     * @return array{runCount: int, costNanoCredits: ?int}
     */
    private function foldRowInto(?array $runningTotal, int|string|null $costNanoCredits): array
    {
        $runCount = ($runningTotal['runCount'] ?? 0) + 1;
        $costSoFar = $runningTotal['costNanoCredits'] ?? null;

        if (null !== $costNanoCredits) {
            $costSoFar = (int) $costNanoCredits + ($costSoFar ?? 0);
        }

        return ['runCount' => $runCount, 'costNanoCredits' => $costSoFar];
    }
}
