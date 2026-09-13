<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A row's viewed flag and the instant it was set: `EntryState::markViewed()`
 * always stamps both together, and bundling keeps EntryListRow's
 * constructor under PHPMD's parameter-count gate.
 */
final readonly class EntryListRowViewState
{
    public function __construct(
        public bool $isViewed,
        public ?\DateTimeImmutable $viewedAt,
    ) {
    }
}
