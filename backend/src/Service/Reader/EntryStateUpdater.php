<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Dto\Entry\UpdateEntryStateRequest;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Mirrors isHidden/isViewed onto every other subscribed copy of the same
 * article (#496) so a collapse-hidden duplicate cannot resurface as unread;
 * isFavorite/isKept stay per-copy.
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

    public function apply(User $user, EntryListRow $row, UpdateEntryStateRequest $request): EntryState
    {
        $state = $this->states->resolve($user, $row);
        $this->applyTo($state, $request);
        $this->mirror($user, $row, $request);
        $this->em->flush();

        return $state;
    }

    private function applyTo(EntryState $state, UpdateEntryStateRequest $request): void
    {
        if ($request->isHidden !== null) {
            // Unread also clears "opened" (EntryState::markUnread, #478), so the
            // rule reaches every client, not just the web app.
            $request->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }
        if ($request->isViewed !== null) {
            // markViewed sets only the viewed flag; ViewedImpliesHiddenListener
            // adds the hidden flag on flush. clearViewed leaves the entry hidden.
            $request->isViewed ? $state->markViewed($this->clock->now()) : $state->clearViewed();
        }
    }

    private function mirror(User $user, EntryListRow $row, UpdateEntryStateRequest $request): void
    {
        if ($request->isHidden === null && $request->isViewed === null) {
            return;
        }
        $hash = $row->entry->getUrlHash();
        if ($hash === null) {
            return;
        }

        $siblings = $this->rows->siblingRowsForUser($hash, (int) $row->entry->getId(), (int) $user->getId());
        foreach ($siblings as $siblingRow) {
            $this->mirrorOnto($this->states->resolve($user, $siblingRow), $request);
        }
    }

    private function mirrorOnto(EntryState $sibling, UpdateEntryStateRequest $request): void
    {
        // Same isHidden/isViewed invariants as applyTo() above (#478,
        // ViewedImpliesHiddenListener): mirroring must not sidestep them.
        if ($request->isHidden !== null) {
            $request->isHidden ? $sibling->hide($this->clock->now()) : $sibling->markUnread();
        }
        if ($request->isViewed !== null) {
            $request->isViewed ? $sibling->markViewed($this->clock->now()) : $sibling->clearViewed();
        }
    }
}
