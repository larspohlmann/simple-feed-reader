<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Mirrors isHidden/isViewed onto every other subscribed copy of the same article (#496), so a collapse-hidden
 * duplicate cannot resurface as unread; isFavorite/isKept stay per copy.
 */
final readonly class EntryStateUpdater
{
    public function __construct(
        private EntryStateResolver $states,
        private EntryListRepository $rows,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function apply(User $user, EntryListRow $row, EntryStateChange $change): EntryState
    {
        $state = $this->states->resolve($user, $row);
        $this->applyTo($state, $change);
        $this->mirror($user, $row, $change);
        $this->em->flush();

        return $state;
    }

    private function applyTo(EntryState $state, EntryStateChange $change): void
    {
        if ($change->isHidden !== null) {
            // Unread also clears "opened" (EntryState::markUnread, #478), so the
            // rule reaches every client, not just the web app.
            $change->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($change->isFavorite !== null) {
            $change->isFavorite ? $state->markFavorite() : $state->clearFavorite();
        }
        if ($change->isKept !== null) {
            $change->isKept ? $state->markKept() : $state->clearKept();
        }
        if ($change->isViewed !== null) {
            // markViewed sets only the viewed flag; ViewedImpliesHiddenListener
            // adds the hidden flag on flush. clearViewed leaves the entry hidden.
            $change->isViewed ? $state->markViewed($this->clock->now()) : $state->clearViewed();
        }
    }

    private function mirror(User $user, EntryListRow $row, EntryStateChange $change): void
    {
        if ($change->isHidden === null && $change->isViewed === null) {
            return;
        }
        $hash = $row->entry->getUrlHash();
        if ($hash === null) {
            return;
        }

        $siblings = $this->rows->siblingRowsForUser($user->requireId(), $hash, $row->entry->requireId());
        foreach ($siblings as $siblingRow) {
            $this->mirrorOnto($this->states->resolve($user, $siblingRow), $change);
        }
    }

    private function mirrorOnto(EntryState $sibling, EntryStateChange $change): void
    {
        // Same isHidden/isViewed invariants as applyTo() above (#478,
        // ViewedImpliesHiddenListener): mirroring must not sidestep them.
        if ($change->isHidden !== null) {
            $change->isHidden ? $sibling->hide($this->clock->now()) : $sibling->markUnread();
        }
        if ($change->isViewed !== null) {
            $change->isViewed ? $sibling->markViewed($this->clock->now()) : $sibling->clearViewed();
        }
    }
}
