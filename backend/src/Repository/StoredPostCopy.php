<?php

declare(strict_types=1);

namespace App\Repository;

/** One stored copy of a post: which entry, in which feed, under which guid. */
final readonly class StoredPostCopy
{
    public function __construct(
        public int $entryId,
        public int $feedId,
        public string $guidHash,
    ) {
    }
}
