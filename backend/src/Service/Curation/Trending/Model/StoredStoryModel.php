<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoredStoryModel
{
    /** @param list<int> $entryIds the story's members whose entry falls inside the build's window */
    public function __construct(
        public int $id,
        public array $entryIds,
        public ?int $fallbackEntryId = null,
        public bool $titled = false,
    ) {
    }
}
