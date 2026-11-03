<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Entity\EntryState;

/** An entry's state after a change, with its share, which lives apart from the state row. */
final readonly class UpdatedEntryStateModel
{
    public function __construct(
        public EntryState $state,
        public ?\DateTimeImmutable $sharedAt,
    ) {
    }
}
