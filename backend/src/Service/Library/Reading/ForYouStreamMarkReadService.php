<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Entity\User;
use App\Repository\ForYouStreamEntryRepository;

/** Marks read the unread stream matches no newer than $until, by entry state: a watermark would reach past the stream. */
final readonly class ForYouStreamMarkReadService
{
    public function __construct(
        private ForYouStreamEntryRepository $streamEntries,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function markReadUpTo(User $user, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();

        $this->readMarker->markEntriesRead($userId, $this->streamEntries->findUnreadMemberIdsUpTo($userId, $until));
    }
}
