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
    /** @var array<string, true> hashes inserted since the last absorb() */
    private array $insertedGuidHashes = [];

    /** @param array<string, int> $entryIdsByGuidHash the feed's rows as they were before the load */
    public function __construct(
        public readonly int $feedId,
        public readonly bool $acceptsNewEntries,
        private array $entryIdsByGuidHash,
    ) {
    }

    public function knowsEntry(string $guidHash): bool
    {
        return isset($this->entryIdsByGuidHash[$guidHash]) || isset($this->insertedGuidHashes[$guidHash]);
    }

    public function entryId(string $guidHash): ?int
    {
        return $this->entryIdsByGuidHash[$guidHash] ?? null;
    }

    /**
     * Remembers a hash written by an insert whose id is not read back yet, so
     * a file that repeats the same guid inside one feed cannot drive a second
     * insert into the unique (feed_id, guid_hash) index.
     */
    public function markInserted(string $guidHash): void
    {
        $this->insertedGuidHashes[$guidHash] = true;
    }

    /**
     * Takes the feed's rows as they now stand and reports which ids were not
     * there before — the entries this restore created, and the only ones it
     * may hand to the search index.
     *
     * @param array<string, int> $entryIdsByGuidHash
     *
     * @return list<int>
     */
    public function absorb(array $entryIdsByGuidHash): array
    {
        $created = array_diff_key($entryIdsByGuidHash, $this->entryIdsByGuidHash);
        $this->entryIdsByGuidHash = $entryIdsByGuidHash;
        $this->insertedGuidHashes = [];

        return array_values($created);
    }
}
