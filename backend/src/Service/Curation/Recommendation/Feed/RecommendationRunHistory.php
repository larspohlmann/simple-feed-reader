<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed;

use App\Entity\User;
use App\Enum\AiRunKind;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Curation\Recommendation\Feed\Model\HistoryMonthModel;
use App\Service\Curation\Recommendation\Feed\Model\MonthWindowModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryCursorModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryFilterModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryMonthPageModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryOverviewModel;
use App\Service\Foundation\Clock\Model\ViewerTimeZoneModel;

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
     * The total is summed by the database over every kind, not derived from the timeline, so it stays whole when the
     * timeline is capped or a kind filter narrows it.
     */
    public function overview(User $user, ViewerTimeZoneModel $viewer, ?AiRunKind $kind): RunHistoryOverviewModel
    {
        $months = $this->summariser->summarise($this->runs->spendTimeline($user, $kind), $viewer);

        return new RunHistoryOverviewModel(
            $this->runs->totalCostNanoCredits($user),
            $months,
            $this->latestMonthPage($user, $viewer, $months[0] ?? null, $kind),
        );
    }

    public function month(User $user, MonthWindowModel $window, RunHistoryFilterModel $filter): RunHistoryMonthPageModel
    {
        [$rows, $nextCursor] = $this->truncate($this->runs->pageForMonth($user, $window, $filter));

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
        ?AiRunKind $kind,
    ): ?RunHistoryMonthPageModel {
        if (null === $newestMonth) {
            return null;
        }

        $window = MonthWindowModel::fromMonth($newestMonth->month, $viewer);

        return $this->month($user, $window, new RunHistoryFilterModel($kind, null));
    }

    /**
     * @param list<HistoryRow> $rows
     *
     * @return array{0: list<HistoryRow>, 1: ?RunHistoryCursorModel}
     */
    private function truncate(array $rows): array
    {
        if (\count($rows) <= RecommendationRunHistoryRepository::HISTORY_LIMIT) {
            return [$rows, null];
        }

        $kept = \array_slice($rows, 0, RecommendationRunHistoryRepository::HISTORY_LIMIT);
        $lastKept = array_last($kept);

        $nextCursor = new RunHistoryCursorModel($lastKept['createdAt'], $lastKept['kind'], $lastKept['id']);

        return [$kept, $nextCursor];
    }
}
