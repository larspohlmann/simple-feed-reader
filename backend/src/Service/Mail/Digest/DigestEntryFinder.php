<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;

/**
 * The digest's own read of a saved search: everything unread that the search
 * matched since the caller's last send, capped for one email section (#636).
 * Reuses the saved search's own term matching (via EntryListRepository) so a
 * digest section never lists anything the live search page would not.
 */
final readonly class DigestEntryFinder
{
    /** The most entries one saved search contributes to a single digest. */
    public const int PER_SEARCH = 10;

    public function __construct(private EntryListRepository $entries)
    {
    }

    public function matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatches
    {
        $terms = SearchTerms::fromTermAndMode($search->getTerm(), $search->isWholeWord());
        $ids = $this->entries->unreadMatchIdsSince(new EntrySearchQuery($userId, $terms), $since);

        if ($ids === []) {
            return new DigestSearchMatches([], 0);
        }

        // rowsByIdsForUser orders newest-first, so slicing to PER_SEARCH keeps
        // the newest entries; totalCount stays the pre-cap, post-gate count for
        // the "+N more" line and the subject total.
        $rows = $this->entries->rowsByIdsForUser($ids, $userId);

        return new DigestSearchMatches(\array_slice($rows, 0, self::PER_SEARCH), \count($rows));
    }
}
