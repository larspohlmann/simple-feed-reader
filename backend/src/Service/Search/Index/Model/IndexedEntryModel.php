<?php

declare(strict_types=1);

namespace App\Service\Search\Index\Model;

/** One entry as the index sees it: EntryIndexer reduces the body to plain text, so no HTML reaches the engine. */
final readonly class IndexedEntryModel
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
