<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Service\Library\Reading\Model\EntryStateChangeModel;
use App\Service\Library\Reading\Model\UpdatedEntryStateModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * isHidden and isViewed reach the entry's copies, its item's entries or, for an article, every entry at its address,
 * so a collapse-hidden copy does not resurface as unread.
 */
final readonly class EntryStateUpdater
{
    public function __construct(
        private EntryStateResolver $states,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EntryShares $shares,
        private CopyReadStatePropagator $copyReadStates,
    ) {
    }

    public function apply(User $user, EntryListRow $row, EntryStateChangeModel $change): UpdatedEntryStateModel
    {
        $state = $this->states->resolve($user, $row);
        $this->applyReadFlags($state, $change);
        $this->applyMarks($state, $change);
        $sharedAt = $this->applyShared($user, $row, $change);
        $this->entityManager->flush();
        $this->copyReadStates->spreadChange($user->requireId(), $row->entry, $change);

        return new UpdatedEntryStateModel($state, $sharedAt);
    }

    private function applyReadFlags(EntryState $state, EntryStateChangeModel $change): void
    {
        if ($change->isHidden !== null) {
            // EntryState::markUnread() also clears "opened", so the rule reaches every client, not just the web app.
            $change->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($change->isViewed !== null) {
            // markViewed sets only the viewed flag; ViewedImpliesHiddenListener
            // adds the hidden flag on flush. clearViewed leaves the entry hidden.
            $change->isViewed ? $state->markViewed($this->clock->now()) : $state->clearViewed();
        }
    }

    private function applyMarks(EntryState $state, EntryStateChangeModel $change): void
    {
        if ($change->isFavorite !== null) {
            $change->isFavorite ? $state->markFavorite() : $state->clearFavorite();
        }
        if ($change->isKept !== null) {
            $change->isKept ? $state->markKept() : $state->clearKept();
        }
    }

    private function applyShared(User $user, EntryListRow $row, EntryStateChangeModel $change): ?\DateTimeImmutable
    {
        if (null === $change->isShared) {
            return $row->marks->sharedAt;
        }
        if (!$change->isShared) {
            $this->shares->remove($user, $row->entry);

            return null;
        }

        return $this->shares->record($user, $row->entry) ?? $row->marks->sharedAt;
    }
}
