<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ListOrder;
use App\Pagination\EntryCursor;

/**
 * Everything one combined saved-search read needs (#1116): the caller, the
 * searches by id, and the page.
 */
final readonly class SavedSearchListQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param list<int> $savedSearchIds
     */
    public function __construct(
        public int $userId,
        public array $savedSearchIds,
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
}
