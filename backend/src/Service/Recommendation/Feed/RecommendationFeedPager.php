<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Feed;

use App\Pagination\Exception\MalformedCursorException;
use App\Pagination\RecommendationCursor;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use App\Repository\RecommendationItemRepository;
use App\Service\Recommendation\Feed\Model\RecommendationFeedPageModel;
use OpenTelemetry\API\Instrumentation\WithSpan;

final readonly class RecommendationFeedPager
{
    public function __construct(
        private RecommendationItemRepository $items,
    ) {
    }

    #[WithSpan]
    public function page(ForYouFeedQuery $query): RecommendationFeedPageModel
    {
        $rows = $this->items->listForYou($query, self::cursorOf($query));

        return new RecommendationFeedPageModel($rows, $this->nextCursorFor($rows, $query->limit));
    }

    /** A garbled For You cursor restarts the feed instead of breaking it, unlike EntryCursor. */
    private static function cursorOf(ForYouFeedQuery $query): ?RecommendationCursor
    {
        if (null === $query->cursor || '' === $query->cursor) {
            return null;
        }

        try {
            return RecommendationCursor::decode($query->cursor);
        } catch (MalformedCursorException) {
            return null;
        }
    }

    /**
     * A full page implies there may be more; hand back a cursor from the last
     * row. A short page cannot have a next page.
     *
     * @param list<RecommendationFeedRow> $rows
     */
    private function nextCursorFor(array $rows, int $limit): ?string
    {
        if ($rows === [] || \count($rows) < $limit) {
            return null;
        }

        $last = $rows[array_key_last($rows)];

        return RecommendationCursor::encode($last->runId, $last->position);
    }
}
