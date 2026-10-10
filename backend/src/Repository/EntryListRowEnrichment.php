<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkCardContent;

/** The collections a loader batch-attaches to a row after hydration: feed categories, saved searches and shared-link cards. */
final readonly class EntryListRowEnrichment
{
    public function __construct(
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
        /** @var list<LinkCardContent> the links the post shares, in post order */
        public array $linkCards = [],
    ) {
    }
}
