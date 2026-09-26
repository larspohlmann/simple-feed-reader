<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * A page of the user's for-you feed (#321), enriched like every entry list, with the annotations the
 * reader's "show reasons" preference allows. Debug is deliberately not consulted here: it keeps the
 * per-run call logs, not a second way into the feed's annotations (#576).
 */
final readonly class ForYouFeed
{
    public function __construct(
        private RecommendationFeedPager $pager,
        private RecommendationSettingsResolver $settings,
        private EntryListRowEnricher $enricher,
    ) {
    }

    #[WithSpan]
    public function page(ForYouFeedQuery $query): ForYouFeedPage
    {
        $page = $this->pager->page($query);

        $visibility = new FeedAnnotationVisibility(
            showExplanation: $this->settings->forUser($query->user)->showReasons,
        );

        return new ForYouFeedPage(
            $this->enrichedRows($page->rows, $query->userId()),
            $page->nextCursor,
            $visibility,
        );
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<RecommendationFeedRow>
     */
    private function enrichedRows(array $rows, int $userId): array
    {
        $entryRows = $this->enricher->enrich(
            array_map(static fn (RecommendationFeedRow $row): EntryListRow => $row->row, $rows),
            $userId,
        );

        return array_map(
            static fn (RecommendationFeedRow $row, EntryListRow $entryRow) => $row->withRow($entryRow),
            $rows,
            $entryRows,
        );
    }
}
