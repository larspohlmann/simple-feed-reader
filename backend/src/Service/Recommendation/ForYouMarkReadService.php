<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Service\Reader\BulkEntryReadMarker;

/**
 * Marks the caller's for-you picks read.
 *
 * Deliberately NOT `MarkReadService`: that one advances each subscription's
 * read watermark, and the for-you feed is not scoped to a feed. A watermark
 * here would mark entries read in All items that the reader never saw among
 * their picks — and the watermark is what emptied the recommendation candidate
 * pool in #665. Only the picked entries' own states change.
 */
final readonly class ForYouMarkReadService
{
    public function __construct(
        private RecommendationItemRepository $items,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();

        $this->readMarker->markRead($userId, $this->items->unreadEntryIdsForYou($userId, $until));
    }
}
