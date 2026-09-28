<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;

/**
 * The one place a lazily created EntryState row comes into existence. It is seeded hidden when the watermark
 * already reads the entry, so no caller can flip a read entry back to unread; the bulk writers (EntryReadMarker,
 * RestoreEntryLoader) create rows read from birth instead.
 */
final readonly class EntryStateResolver
{
    public function __construct(
        private EntryStateRepository $states,
    ) {
    }

    /**
     * An idempotent insert then a reload, never ORM new+persist, which would race concurrent writers into a
     * duplicate-key flush (#496).
     */
    public function resolve(User $user, EntryListRow $row): EntryState
    {
        $userId = $user->requireId();
        $entryId = $row->entry->requireId();

        $existing = $this->states->findOneForUserEntry($userId, $entryId);
        if ($existing !== null) {
            return $existing;
        }

        $this->states->ensureRow($userId, $entryId, $row->isHidden ? $row->markedReadUntil : null);

        $created = $this->states->findOneForUserEntry($userId, $entryId);
        if ($created === null) {
            throw new \LogicException('ensureRow created the entry_state row, so the reload cannot be null.');
        }

        return $created;
    }
}
