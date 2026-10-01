<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use App\Service\Recommendation\Feed\Model\FeedAnnotationVisibilityModel;
use App\Service\Recommendation\Feed\Model\ForYouFeedPageModel;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;

/**
 * A page of the user's for-you feed, enriched like every entry list, annotated as the reader's "show reasons" allows.
 */
final readonly class ForYouFeed
{
    public function __construct(
        private RecommendationFeedPager $pager,
        private RecommendationSettingsResolver $settings,
        private EntryListRowEnricher $enricher,
    ) {
    }

    public function page(ForYouFeedQuery $query): ForYouFeedPageModel
    {
        $page = $this->pager->page($query);

        $visibility = new FeedAnnotationVisibilityModel(
            showExplanation: $this->settings->forUser($query->user)->showReasons,
        );

        return new ForYouFeedPageModel(
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
