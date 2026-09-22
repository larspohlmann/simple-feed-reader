<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

/**
 * Everything one combined saved-search read needs (#1116): the caller, the
 * searches by id — membership is a table now, so the read needs no terms —
 * and the page.
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
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }
}
