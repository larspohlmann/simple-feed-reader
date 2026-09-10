<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Service\Search\SavedSearchTerms;
use App\Service\Search\SavedSearchUnreadMatchSource;

/**
 * Marks read every unread entry that matches any of the caller's saved
 * searches. The match set now comes through SavedSearchUnreadMatchSource, so it
 * is engine-consistent when Meilisearch is configured and the LIKE set when it
 * is not — exactly the set the combined list shows on the same path (#973).
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchTerms $terms,
        private SavedSearchUnreadMatchSource $matches,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->matches->unreadMatchIdsUpTo(
            $userId,
            $this->terms->forUser($userId),
            $until,
        ));
    }
}
