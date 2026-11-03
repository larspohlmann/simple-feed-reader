<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Reading\EntryShareStore\EntryShareStoreInterface;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;

/** The one recording path for a share, whichever client or network action reports it. */
final readonly class EntryShares
{
    public function __construct(
        private EntryShareStoreInterface $shares,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ignored while the user does not record shares; what is already shared stays.
     *
     * @throws DbalException
     */
    public function record(User $user, Entry $entry): void
    {
        if (!$user->getPreferences()->recordsShares()) {
            return;
        }

        $this->shares->record($user->requireId(), $entry->requireId(), $this->clock->now());
    }

    public function remove(User $user, Entry $entry): void
    {
        $this->shares->remove($user->requireId(), $entry->requireId());
    }

    public function sharedAtOf(User $user, Entry $entry): ?\DateTimeImmutable
    {
        return $this->shares->sharedAtFor($user->requireId(), $entry->requireId());
    }
}
