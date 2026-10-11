<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkCardContent;

final readonly class EntryListRowEnrichment
{
    public function __construct(
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
        /** @var list<array{id: int, slug: string, term: string}> owned saved searches this entry belongs to, sidebar order */
        public array $savedSearches = [],
        /** @var list<LinkCardContent> the links the post shares, in post order */
        public array $linkCards = [],
        public bool $pending = false,
    ) {
    }

    /** @param list<string> $categories */
    public function withCategories(array $categories): self
    {
        return new self($categories, $this->savedSearches, $this->linkCards, $this->pending);
    }

    /** @param list<array{id: int, slug: string, term: string}> $savedSearches */
    public function withSavedSearches(array $savedSearches): self
    {
        return new self($this->categories, $savedSearches, $this->linkCards, $this->pending);
    }

    /** @param list<LinkCardContent> $linkCards */
    public function withLinkCards(array $linkCards): self
    {
        return new self($this->categories, $this->savedSearches, $linkCards, $this->pending);
    }

    public function withPending(bool $pending): self
    {
        return new self($this->categories, $this->savedSearches, $this->linkCards, $pending);
    }
}
