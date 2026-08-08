<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Http\RecommendationCursor;
use App\Repository\EntryQuery;
use App\Repository\RecommendationFeedRow;
use App\Repository\RecommendationItemRepository;

final readonly class RecommendationFeedPager
{
    public function __construct(
        private RecommendationItemRepository $items,
    ) {
    }

    /**
     * A malformed cursor decodes to null, which yields the first page rather
     * than an error — the same leniency EntryCursor deliberately does NOT
     * have, because here a stale/garbled cursor should never break the feed.
     */
    public function page(int $userId, ?string $cursor, int $limit): RecommendationFeedPage
    {
        $limit = max(1, min($limit, EntryQuery::MAX_LIMIT));
        $decodedCursor = $cursor === null || $cursor === '' ? null : RecommendationCursor::decode($cursor);

        $rows = $this->items->listForYou($userId, $decodedCursor, $limit);

        return new RecommendationFeedPage($rows, $this->nextCursorFor($rows, $limit));
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
