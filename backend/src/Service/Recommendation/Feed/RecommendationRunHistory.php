<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Entity\User;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Recommendation\Feed\Model\HistoryMonthModel;
use App\Service\Recommendation\Feed\Model\MonthWindowModel;
use App\Service\Recommendation\Feed\Model\RunHistoryMonthPageModel;
use App\Service\Recommendation\Feed\Model\RunHistoryOverviewModel;

/**
 * The run history: the overview card and the month pages it expands into.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
final readonly class RecommendationRunHistory
{
    public function __construct(
        private RecommendationRunHistoryRepository $runs,
        private HistoryMonthSummariser $summariser,
    ) {
    }

    /**
     * The total is summed by the database, not derived from the timeline, so it stays whole if the timeline is ever
     * capped.
     */
    public function overview(User $user, ViewerTimeZoneModel $viewer): RunHistoryOverviewModel
    {
        $months = $this->summariser->summarise($this->runs->spendTimeline($user), $viewer);

        return new RunHistoryOverviewModel(
            $this->runs->totalCostNanoCredits($user),
            $months,
            $this->latestMonthPage($user, $viewer, $months[0] ?? null),
        );
    }

    public function month(User $user, MonthWindowModel $window, ?int $beforeRunId): RunHistoryMonthPageModel
    {
        [$rows, $nextCursor] = $this->truncate($this->runs->pageForMonth($user, $window, $beforeRunId));

        return new RunHistoryMonthPageModel($window->month, $rows, $nextCursor);
    }

    /**
     * The overview's `latest`: the first page of the newest month that has a
     * run in it, not the calendar month the server clock reads. Null when the
     * account has never run, since there is then no month to open.
     */
    private function latestMonthPage(
        User $user,
        ViewerTimeZoneModel $viewer,
        ?HistoryMonthModel $newestMonth,
    ): ?RunHistoryMonthPageModel {
        if (null === $newestMonth) {
            return null;
        }

        return $this->month($user, MonthWindowModel::of($newestMonth->month, $viewer), null);
    }

    /**
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
