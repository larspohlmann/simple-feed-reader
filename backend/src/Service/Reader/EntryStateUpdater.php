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
 * Applies a state PATCH to its target, then mirrors isHidden/isViewed onto
 * every other subscribed copy of the same article (#496): two feeds carrying
 * the same story must read and hide together, or a duplicate the collapse
 * hid behind the target reappears as unread once the target itself is read.
 * isFavorite/isKept stay local — favouriting one copy says nothing about the
 * other feed's copy.
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
            $request->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($request->isFavorite !== null) {
            $state->setIsFavorite($request->isFavorite);
        }
        if ($request->isKept !== null) {
            $state->setIsKept($request->isKept);
        }
        if ($request->isViewed !== null) {
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
        if ($request->isHidden !== null) {
            $request->isHidden ? $sibling->hide($this->clock->now()) : $sibling->markUnread();
        }
        if ($request->isViewed !== null) {
            $request->isViewed ? $sibling->markViewed($this->clock->now()) : $sibling->clearViewed();
        }
    }
}
