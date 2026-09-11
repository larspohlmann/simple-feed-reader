<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;

/**
 * The single place a lazily created EntryState row comes into existence.
 *
 * Exceptions: the bulk writers RestoreEntryLoader and SearchMarkReadService
 * build rows read from birth, so the watermark hazard below doesn't apply.
 * Any row whose isHidden is NOT decided up front belongs here.
 *
 * Read state is effective, not stored: an entry with no row is read when the
 * subscription's mark-all-read watermark covers it (see
 * EntryRepository::rowIsRead(), EntryStateRepository::unreadCountsForUser()).
 * Materialising it with the field default isHidden=false would flip a read
 * entry back to unread and raise the badge, so every lazily created row is
 * seeded from the watermark here — no caller (favorite, keep, viewed) can
 * reintroduce that hazard.
 */
final readonly class EntryStateResolver
{
    public function __construct(
        private EntryStateRepository $states,
    ) {
    }

    /**
     * The user's state row, created when absent by an idempotent insert then
     * reloaded (EntryStateRepository::ensureRow) — never ORM new+persist, which
     * would race concurrent writers into a duplicate-key flush.
     */
    public function resolve(User $user, EntryListRow $row): EntryState
    {
        $userId = (int) $user->getId();
        $entryId = (int) $row->entry->getId();

        $existing = $this->states->findOneForUserEntry($userId, $entryId);
        if ($existing !== null) {
            return $existing;
        }

        $this->states->ensureRow($userId, $entryId, $row->isHidden, $row->isHidden ? $row->markedReadUntil : null);

        $created = $this->states->findOneForUserEntry($userId, $entryId);
        if ($created === null) {
            throw new \LogicException('ensureRow created the entry_state row, so the reload cannot be null.');
        }

        return $created;
    }
}
