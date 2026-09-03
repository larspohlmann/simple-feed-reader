<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Folds a repository's spend timeline into one HistoryMonth per calendar month, newest
 * first, for the run-history card's collapsible sections (#409).
 *
 * Pure data shaping, no persistence: the timeline is already every run the account owns
 * (RecommendationRunHistoryRepository::spendTimeline()), and grouping it here rather than
 * in the database is the tradeoff recorded on that method — DQL has no portable month
 * extraction, and the buckets must be cut in the viewer's timezone while the column holds
 * naive UTC.
 */
final readonly class HistoryMonthSummariser
{
    /**
     * @param list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}> $spendTimeline
     *
     * @return list<HistoryMonth> newest month first
     */
    public function summarise(array $spendTimeline, ViewerTimeZone $viewer): array
    {
        $totals = [];

        foreach ($spendTimeline as $row) {
            // setTimezone() converts, it does not reinterpret, so this is correct
            // only because the hydrated value carries UTC: Doctrine builds it in
            // PHP's default zone and Kernel::boot() pins that to UTC (KernelTimezoneTest).
            // Lose the pin and the rows still cut correctly (the window binds
            // explicit-UTC boundaries), but these headers drift by the host offset —
            // the header-contradicts-its-rows failure ViewerTimeZone's docblock warns of.
            $month = $row['createdAt']->setTimezone($viewer->zone)->format('Y-m');
            $totals[$month] = $this->foldRowInto($totals[$month] ?? null, $row['costNanoCredits']);
        }

        krsort($totals);

        return array_map(
            static fn (string $month, array $total): HistoryMonth => new HistoryMonth(
                $month,
                $total['runCount'],
                $total['costNanoCredits'],
            ),
            array_keys($totals),
            array_values($totals),
        );
    }

    /**
     * One row's contribution to its month's running total. The count grows
     * on every row, priced or not; the cost only grows on a priced one, and
     * stays null for as long as none of them are — null and zero are
     * different answers here, see HistoryMonth.
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
