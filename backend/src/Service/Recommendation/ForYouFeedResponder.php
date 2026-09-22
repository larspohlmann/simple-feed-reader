<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Http\FeedAnnotationVisibility;
use App\Http\RecommendationFeedJson;
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryListRow;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use App\Repository\SavedSearchMembershipLoader;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * What JSON the for-you feed page returns for a user — paginates their
 * recommendation feed, then annotates each entry according to their
 * recommendation settings (#321). The reason and its score are one
 * explanation and follow one switch — the reader's "show reasons" preference.
 * Debug is deliberately not consulted here: it keeps the per-run call logs,
 * not a second way into the feed's annotations (#576).
 */
final readonly class ForYouFeedResponder
{
    public function __construct(
        private RecommendationFeedPager $pager,
        private RecommendationSettingsResolver $settings,
        private EntryCategoryLoader $categoryLoader,
        private SavedSearchMembershipLoader $savedSearchLoader,
    ) {
    }

    /** @return array<string, mixed> */
    #[WithSpan]
    public function page(ForYouFeedQuery $query): array
    {
        $page = $this->pager->page($query);

        $visibility = new FeedAnnotationVisibility(
            showExplanation: $this->settings->forUser($query->user)->showReasons,
        );

        $rows = $this->enrichedRows($page->rows, $query->userId());

        return RecommendationFeedJson::page($rows, $page->nextCursor, $visibility);
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<RecommendationFeedRow>
     */
    private function enrichedRows(array $rows, int $userId): array
    {
        $entryRows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto(
                array_map(static fn (RecommendationFeedRow $row): EntryListRow => $row->row, $rows),
            ),
            $userId,
        );

        return array_map(
            static fn (RecommendationFeedRow $row, EntryListRow $entryRow) => $row->withRow($entryRow),
            $rows,
            $entryRows,
        );
    }
}
