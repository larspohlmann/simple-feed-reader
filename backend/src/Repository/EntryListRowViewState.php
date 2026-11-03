<?php

declare(strict_types=1);

namespace App\Repository;

/** A row's viewed flag and the instants its reading histories sort by: when it was viewed and when shared. */
final readonly class EntryListRowViewState
{
    public function __construct(
        public bool $isViewed,
        public ?\DateTimeImmutable $viewedAt,
        public ?\DateTimeImmutable $sharedAt = null,
    ) {
    }
}
