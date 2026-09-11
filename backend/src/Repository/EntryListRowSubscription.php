<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The subscription identity a row's cross-feed listing names: its id and
 * display title. Bundled as one value object so EntryListRow's constructor
 * stays under PHPMD's parameter-count gate.
 */
final readonly class EntryListRowSubscription
{
    public function __construct(
        public int $id,
        public string $title,
    ) {
    }
}
