<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunHistoryRepository;

/**
 * Assembles the two run-history payloads (#409): the overview the card opens
 * on — the all-time total, one summary per calendar month, and the newest
 * month's first page — and the further month page a reader pages into.
 *
 * Owns the limit-plus-one truncation pageForMonth() deliberately over-reads by.
 * Both public methods need a page, so that logic lives once in truncate() below,
 * not once in the controller and once in the JSON mapper — an earlier controller
 * attempt was rejected for it.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 * @phpstan-import-type MonthPagePayload from RecommendationRunHistoryJson
 * @phpstan-import-type OverviewPayload from RecommendationRunHistoryJson
 */
final readonly class RecommendationRunHistoryView
{
    public function __construct(
        private RecommendationRunHistoryRepository $runs,
        private HistoryMonthSummariser $summariser,
    ) {
    }

    /**
     * The all-time total is summed by the database over the same rows
     * spendTimeline() returns. The duplication is deliberate: the timeline may
     * gain a cap one day, and the SUM keeps the account total honest when it
     * does. Deriving the total from the timeline would silently reduce it to
     * "the total of what the timeline still covers".
     *
     * @return OverviewPayload
     */
    public function overview(User $user, ViewerTimeZone $viewer): array
    {
        $months = $this->summariser->summarise($this->runs->spendTimeline($user), $viewer);

        return RecommendationRunHistoryJson::overview(
            $this->runs->totalCostNanoCredits($user),
            $months,
            $this->latestMonthPage($user, $viewer, $months[0] ?? null),
        );
    }

    /** @return MonthPagePayload */
    public function month(User $user, MonthWindow $window, ?int $beforeRunId): array
    {
        [$rows, $nextCursor] = $this->truncate($this->runs->pageForMonth($user, $window, $beforeRunId));

        return RecommendationRunHistoryJson::monthPage($window->month, $rows, $nextCursor);
    }

    /**
     * The overview's `latest`: the first page of the newest month that has a
     * run in it, not the calendar month the server clock reads. Null when the
     * account has never run, since there is then no month to open.
     *
     * @return ?MonthPagePayload
     */
    private function latestMonthPage(User $user, ViewerTimeZone $viewer, ?HistoryMonth $newestMonth): ?array
    {
        if (null === $newestMonth) {
            return null;
        }

        return $this->month($user, MonthWindow::of($newestMonth->month, $viewer), null);
    }

    /**
     * Splits the repository's HISTORY_LIMIT + 1 rows into the page the wire
     * shape keeps and the cursor that says whether another one exists. The
     * extra row is read purely as a yes/no signal and never shown, so it is
     * dropped here rather than passed on for a caller to remember to trim.
     *
     * @param list<HistoryRow> $rows
     *
     * @return array{0: list<HistoryRow>, 1: ?int}
     */
    private function truncate(array $rows): array
    {
        if (\count($rows) <= RecommendationRunHistoryRepository::HISTORY_LIMIT) {
            return [$rows, null];
        }

        $kept = \array_slice($rows, 0, RecommendationRunHistoryRepository::HISTORY_LIMIT);

        return [$kept, $kept[array_key_last($kept)]['id']];
    }
}
