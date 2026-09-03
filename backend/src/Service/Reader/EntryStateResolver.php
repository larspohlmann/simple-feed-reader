<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The user's state row for the entry, created and persisted when absent.
     *
     * Takes a list row rather than a bare Entry because the row already carries
     * the effective read state: the projection folds the watermark in, so this
     * needs no second query and no second copy of the watermark rule.
     */
    public function resolve(User $user, EntryListRow $row): EntryState
    {
        $entry = $row->entry;
        $existing = $this->states->findOneForUserEntry((int) $user->getId(), (int) $entry->getId());
        if ($existing !== null) {
            return $existing;
        }

        $state = new EntryState($user, $entry);
        $this->seedReadState($state, $row);
        $this->em->persist($state);

        return $state;
    }

    private function seedReadState(EntryState $state, EntryListRow $row): void
    {
        if (!$row->isHidden) {
            return;
        }

        $state->setIsHidden(true);
        // The watermark, not the current time: the entry became read when the
        // sweep ran, and the clock would claim a read that never happened at
        // this instant. It is also the same value the sweep itself compared
        // against, so the row now states exactly what the watermark implied.
        $state->setHiddenAt($row->markedReadUntil);
    }
}
