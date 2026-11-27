<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Entity\User;
use App\Repository\EntryListRow;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/** The reader's own like of an entry, kept on its state where no network can take it. */
final readonly class LocalLikes
{
    public function __construct(
        private EntryStateResolver $stateResolver,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function like(User $user, EntryListRow $row): void
    {
        if ($row->marks->isLiked) {
            return;
        }
        $state = $this->stateResolver->resolve($user, $row);
        $state->replaceMarks($state->marks()->withLikedAt($this->clock->now()));
        $this->entityManager->flush();
    }

    public function unlike(User $user, EntryListRow $row): void
    {
        if (!$row->marks->isLiked) {
            return;
        }
        $state = $this->stateResolver->resolve($user, $row);
        $state->replaceMarks($state->marks()->withoutLike());
        $this->entityManager->flush();
    }
}
