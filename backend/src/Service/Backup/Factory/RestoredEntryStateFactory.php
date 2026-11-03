<?php

declare(strict_types=1);

namespace App\Service\Backup\Factory;

use App\Entity\BackedUpReadMark;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Service\Backup\Dto\EntryStateLine;
use Psr\Clock\ClockInterface;

final readonly class RestoredEntryStateFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function create(User $user, Entry $entry, EntryStateLine $line): EntryState
    {
        $state = new EntryState($user, $entry);
        $state->restoreReadMark(new BackedUpReadMark($line->isHidden, $line->hiddenAt));
        if ($line->isFavorite) {
            $state->markFavorite();
        }
        if ($line->isKept) {
            $state->markKept();
        }
        if ($line->isViewed) {
            // A "viewed" line without its timestamp keeps the flag and takes the restore's own time.
            $state->markViewed($line->viewedAt ?? $this->clock->now());
        }

        return $state;
    }
}
