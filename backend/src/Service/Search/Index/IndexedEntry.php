<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

/**
 * One entry as the index needs to see it. The caller (EntryIndexer) is the
 * one place that knows how to turn an Entry into this — including reducing its
 * HTML body to plain text with PlainText::from() — so nothing in this
 * namespace, and nothing MeilisearchIndex sends over the wire, ever touches
 * HTML.
 */
final readonly class IndexedEntry
{
    public function __construct(
        public int $id,
        public int $feedId,
        public string $title,
        public ?string $summary,
        public ?string $content,
        public ?string $feedTitle,
        public \DateTimeImmutable $effectiveDate,
    ) {
    }
}
