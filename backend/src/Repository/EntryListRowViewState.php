<?php

declare(strict_types=1);

namespace App\Repository;

/** A row's viewed flag and instant (`EntryState::markViewed()` stamps both) and when it was shared. */
final readonly class EntryListRowViewState
{
    public function __construct(
        public bool $isViewed,
        public ?\DateTimeImmutable $viewedAt,
        public ?\DateTimeImmutable $sharedAt = null,
    ) {
    }
}
