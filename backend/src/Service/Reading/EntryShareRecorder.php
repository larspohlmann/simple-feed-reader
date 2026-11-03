<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Entry;
use App\Entity\User;
use App\Repository\EntryListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Registers an entry as shared when the user shared it on a network outside the share sheet, as a repost or quote. */
final readonly class EntryShareRecorder
{
    public function __construct(
        private EntryListRepository $rows,
        private EntryStateResolver $states,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function record(User $user, Entry $entry): void
    {
        if (!$user->getPreferences()->recordsShares()) {
            return;
        }

        $row = $this->rows->getRowForUser($user->requireId(), $entry->requireId());
        $this->states->resolve($user, $row)->markShared($this->clock->now());
        $this->entityManager->flush();
    }
}
