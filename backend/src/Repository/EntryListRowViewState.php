<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A row's viewed flag and the instant it was set, bundled because
 * `EntryState::markViewed()` always stamps both together: one is never
 * meaningful without the other. Bundled also to keep EntryListRow's
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
