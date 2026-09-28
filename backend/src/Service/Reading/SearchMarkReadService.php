<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;

/** Marks read every unread entry matching a search term. A search spans every feed, so no watermark scopes it. */
final readonly class SearchMarkReadService
{
    public function __construct(
        private EntryListRepository $entries,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, string $rawQuery, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();

        $this->readMarker->markEntriesRead($userId, $this->entries->unreadMatchingEntryIdsForUser(
            new EntrySearchQuery($userId, SearchTerms::fromInput($rawQuery)),
            $until,
        ));
    }
}
