<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class EntryListRowGroupings
{
    public function __construct(
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
        /** @var list<array{id: int, name: string}> the reader's own labels on this entry, alphabetical */
        public array $labels = [],
    ) {
    }
}
