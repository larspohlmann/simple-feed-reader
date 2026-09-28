<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\RecommendationItemRepository;

/**
 * Marks the caller's for-you picks read by entry state only. A watermark would also mark read what All items
 * shows but the picks never did, and it emptied the recommendation candidate pool in #665.
 */
final readonly class ForYouMarkReadService
{
    public function __construct(
        private RecommendationItemRepository $items,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();

        $this->readMarker->markEntriesRead($userId, $this->items->unreadEntryIdsForYou($userId, $until));
    }
}
