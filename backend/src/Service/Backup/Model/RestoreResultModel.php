<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

/**
 * Rows written, not lines read: a feed or entry the instance already holds is referenced, never re-created, so
 * `feeds` and `entries` can sit far below the file's own counts.
 */
final readonly class RestoreResultModel
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
