<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Everything the entry and entry-state phases need to know about one feed,
 * captured as scalars before the entity manager is cleared: the feed's id,
 * whether this restore may create entries in it, and the guid hash ⇒ entry id
 * map that both de-duplicates inserts and resolves entry states.
 *
 * `acceptsNewEntries` is false as soon as any OTHER account subscribes to the
 * feed. A restore may not push articles into a stranger's unread list, so on
 * a shared feed the file's entries are dropped and the states that referenced
 * them are dropped with them.
 *
 * Mutable by design — it is a per-pass working set, never a shared service.
 */
final class RestoreFeedTarget
{
    /** @param array<string, int> $entryIdsByGuidHash the feed's rows before the load; every batch insert adds its own */
    public function __construct(
        public readonly int $feedId,
        public readonly bool $acceptsNewEntries,
        private array $entryIdsByGuidHash,
    ) {
    }

    public function knowsEntry(string $guidHash): bool
    {
        return isset($this->entryIdsByGuidHash[$guidHash]);
    }

    public function entryId(string $guidHash): ?int
    {
        return $this->entryIdsByGuidHash[$guidHash] ?? null;
    }

    /**
     * @param array<string, int> $entryIdsByGuidHash the ids read back for the rows one batch just inserted
     */
    public function learn(array $entryIdsByGuidHash): void
    {
        $this->entryIdsByGuidHash += $entryIdsByGuidHash;
    }
}
