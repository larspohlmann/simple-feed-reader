<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ListOrder;
use App\Pagination\EntryCursor;

/** The page an entry list reads: where it starts, how many it holds, in which order, and whether it skips read ones. */
final readonly class EntryListPage
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    public function __construct(
        public ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
        public ListOrder $order = ListOrder::NewestFirst,
        public bool $onlyUnread = false,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }

    public function ordering(): EntryListOrdering
    {
        return EntryListOrdering::byPublishedDate($this->order);
    }
}
