<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ListOrder;
use App\Pagination\EntryCursor;

/** Everything one read of a followed story's entries needs: the reader, the story, and the page. */
final readonly class TrendingStoryListQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    public function __construct(
        public int $userId,
        public int $storyId,
        public bool $onlyUnread = false,
        public ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }

    public function ordering(): EntryListOrdering
    {
        return EntryListOrdering::byPublishedDate($this->order);
    }

    public function isFirstPage(): bool
    {
        return $this->cursor === null;
    }
}
