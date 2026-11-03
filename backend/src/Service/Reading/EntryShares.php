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
     * Ignored while the user does not record shares (null); what is already shared stays.
     *
     * @throws DbalException
     */
    public function record(User $user, Entry $entry): ?\DateTimeImmutable
    {
        if (!$user->getPreferences()->recordsShares()) {
            return null;
        }

        $when = $this->clock->now();
        $this->shares->record($user->requireId(), $entry->requireId(), $when);

        return $when;
    }

    public function remove(User $user, Entry $entry): void
    {
        $this->shares->remove($user->requireId(), $entry->requireId());
    }
}
