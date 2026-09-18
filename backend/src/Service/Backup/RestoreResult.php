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
    private function __construct(
        public int $tags,
        public int $savedSearches,
        public int $feeds,
        public int $subscriptions,
        public int $entries,
        public int $entryStates,
    ) {
    }

    public static function ofFoundation(int $tags, int $savedSearches, int $feeds, int $subscriptions): self
    {
        return new self($tags, $savedSearches, $feeds, $subscriptions, entries: 0, entryStates: 0);
    }

    public static function ofEntryPart(int $entries, int $entryStates): self
    {
        return new self(
            tags: 0,
            savedSearches: 0,
            feeds: 0,
            subscriptions: 0,
            entries: $entries,
            entryStates: $entryStates,
        );
    }
}
