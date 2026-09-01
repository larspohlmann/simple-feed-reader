<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;
use App\Service\Search\SavedSearchTerm;

/**
 * The parameter object for the combined saved-search read: every saved search
 * the caller keeps, already parsed into terms. Sits beside EntrySearchQuery
 * because it is the same kind of thing — everything one repository read needs,
 * in one value — and differs in exactly one way: many searches, ORed, rather
 * than one.
 */
final readonly class SavedSearchEntryQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param list<SavedSearchTerm> $savedSearches
     * @param int               $limit          the size the client asked for
     */
    public function __construct(
        public int $userId,
        public array $savedSearches,
        public bool $onlyUnread = false,
        public ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }
}
