<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\User;

final readonly class MarkEntriesReadService
{
    public const int MAX_IDS = 5000;

    public function __construct(private BulkEntryReadMarker $readMarker)
    {
    }

    /** @param list<int> $entryIds */
    public function mark(User $user, array $entryIds): void
    {
        $this->readMarker->markRead((int) $user->getId(), $entryIds);
    }
}
