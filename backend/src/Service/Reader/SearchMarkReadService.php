<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;

/**
 * Marks read every unread entry matching a search term for one user. A search
 * spans every feed and is a content filter, so — unlike feed/tag mark-read —
 * there is no watermark to bump: each matching entry needs an EntryState row,
 * which is what `BulkEntryReadMarker` writes.
 */
final readonly class SearchMarkReadService
{
    public function __construct(
        private EntryListRepository $entries,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, string $rawQuery, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->entries->unreadMatchingEntryIdsForUser(
            new EntrySearchQuery($userId, SearchTerms::fromInput($rawQuery)),
            $until,
        ));
    }
}
