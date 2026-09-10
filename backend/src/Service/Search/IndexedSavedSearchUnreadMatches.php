<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchEntryQuery;

/**
 * The engine-consistent mark-read set. Rather than reimplement engine paging,
 * it drives the indexed list itself: seed the cursor at the inclusive upper
 * bound of $until, then page the unread list to exhaustion, collecting the ids
 * it shows. That reuses the list's union, hydration, access gate and cursor, so
 * the marked set is exactly what the engine-ranked list shows — including the
 * content/typo matches the LIKE predicate never sees.
 *
 * Depends on the raw IndexedSavedSearchEntries, not the fallback: a mid-walk
 * engine failure must surface as SearchEngineUnavailableException so
 * SavedSearchUnreadMatchesWithFallback recomputes the whole set from the
 * database, rather than silently mixing an engine prefix with a LIKE tail.
 */
final readonly class IndexedSavedSearchUnreadMatches implements SavedSearchUnreadMatchSource
{
    /**
     * The largest page SavedSearchEntryQuery accepts: a bigger value would be
     * clamped at construction and the "partial page ends the walk" test below
     * would compare matchCount against a page size the query never used.
     */
    private const int ENUMERATION_PAGE = EntryQuery::MAX_LIMIT;

    public function __construct(private IndexedSavedSearchEntries $list)
    {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        if ($savedSearches === []) {
            return [];
        }

        $ids = [];
        $cursor = EntryCursor::inclusiveUpperBound($until);
        do {
            $result = $this->list->list(new SavedSearchEntryQuery(
                userId: $userId,
                savedSearches: $savedSearches,
                onlyUnread: true,
                cursor: $cursor,
                limit: self::ENUMERATION_PAGE,
            ));
            foreach ($result->rows as $row) {
                $ids[] = (int) $row->entry->getId();
            }
            $cursor = $this->nextCursor($result);
        } while ($cursor !== null);

        return $ids;
    }

    private function nextCursor(SavedSearchEntriesResult $result): ?EntryCursor
    {
        if ($result->matchCount < self::ENUMERATION_PAGE || $result->continuationRow === null) {
            return null;
        }

        $entry = $result->continuationRow->entry;

        return new EntryCursor($entry->getEffectiveDate(), (int) $entry->getId());
    }
}
