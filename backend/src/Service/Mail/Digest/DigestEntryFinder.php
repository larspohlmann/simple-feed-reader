<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\SavedSearchEntryRepository;

/**
 * The digest's own read of a saved search: everything unread in the
 * search's membership table since the caller's last send, capped for one
 * email section (#636). The membership sweep keeps it current (#1116).
 */
final readonly class DigestEntryFinder
{
    /** The most entries one saved search contributes to a single digest. */
    public const int PER_SEARCH = 10;

    public function __construct(
        private SavedSearchEntryRepository $members,
        private EntryListRepository $entries,
    ) {
    }

    public function matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatches
    {
        $ids = $this->members->unreadMemberIdsSince((int) $search->getId(), $userId, $since);
        if ($ids === []) {
            return new DigestSearchMatches([], 0);
        }

        // Hydrate only the newest PER_SEARCH rows, not the whole match set: a wide
        // window can match hundreds, and building every heavy list row to show ten
        // would time the request out (#636). The ids arrive newest-first, so the
        // head is the newest; totalCount stays the full pre-cap count for "+N more".
        $newestIds = \array_slice($ids, 0, self::PER_SEARCH);

        return new DigestSearchMatches($this->entries->rowsByIdsForUser($newestIds, $userId), \count($ids));
    }
}
