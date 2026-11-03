<?php

declare(strict_types=1);

namespace App\Repository;

/** A row's viewed flag, when it was viewed, and when it was shared (the Shared list's sort and its isShared). */
final readonly class EntryListRowViewState
{
    public function __construct(
        public bool $isViewed,
        public ?\DateTimeImmutable $viewedAt,
        public ?\DateTimeImmutable $sharedAt = null,
    ) {
    }
}
