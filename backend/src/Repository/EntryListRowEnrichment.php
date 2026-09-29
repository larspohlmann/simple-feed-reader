<?php

declare(strict_types=1);

namespace App\Repository;

/** The two collections a loader batch-attaches to a row after hydration: feed categories and saved searches. */
final readonly class EntryListRowEnrichment
{
    public function __construct(
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
    ) {
    }
}
