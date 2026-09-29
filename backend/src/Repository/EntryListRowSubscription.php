<?php

declare(strict_types=1);

namespace App\Repository;

/** The subscription a row's cross-feed listing names: its id and display title. */
final readonly class EntryListRowSubscription
{
    public function __construct(
        public int $id,
        public string $title,
    ) {
    }
}
