<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * What a restore actually loaded, counted as rows WRITTEN rather than lines
 * read. `feeds` and `entries` are therefore usually far below the file's own
 * counts on an instance that already holds the same shared rows: a feed
 * another account already subscribes to is referenced, never re-created, and
 * an entry already present is left exactly as it is.
 */
final readonly class RestoreResult
{
    public function __construct(
        public int $tags,
        public int $feeds,
        public int $subscriptions,
        public int $entries,
        public int $entryStates,
    ) {
    }
}
