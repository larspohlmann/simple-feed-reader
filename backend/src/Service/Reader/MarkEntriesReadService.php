<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;
use App\Repository\EntryRepository;

final readonly class MarkEntriesReadService
{
    public function __construct(
        private BulkEntryReadMarker $readMarker,
        private EntryRepository $entries,
    ) {
    }

    /** @param list<int> $entryIds */
    public function mark(User $user, array $entryIds): void
    {
        $this->readMarker->markRead((int) $user->getId(), $this->entries->findExistingIds($entryIds));
    }
}
