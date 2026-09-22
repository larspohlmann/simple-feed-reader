<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchRepository;

/**
 * Marks read every unread member of any of the caller's saved searches no
 * newer than $until — the same rows the combined unread list shows (#1116).
 * Per-entry state rows on purpose: a search spans feeds, so a per-search
 * watermark would leave the entry unread in its feed list.
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchEntryRepository $entries,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();
        $searchIds = array_map(
            static fn (SavedSearch $s): int => (int) $s->getId(),
            $this->savedSearches->findForUser($userId),
        );

        $this->readMarker->markRead($userId, $this->entries->unreadMemberIdsUpTo($userId, $searchIds, $until));
    }
}
