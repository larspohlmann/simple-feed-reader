<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\SavedSearchTerms;

/**
 * Marks read every unread entry that matches any of the caller's saved
 * searches. Like the single-search mark-read, and unlike feed/tag mark-read,
 * there is no watermark to bump: a search spans every feed, so each matching
 * entry needs its own EntryState row.
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchTerms $terms,
        private SavedSearchEntryRepository $entries,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->entries->unreadMatchIdsForSavedSearches(
            $userId,
            $this->terms->forUser($userId),
            $until,
        ));
    }
}
