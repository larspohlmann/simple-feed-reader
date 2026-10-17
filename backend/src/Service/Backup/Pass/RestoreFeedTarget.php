<?php

declare(strict_types=1);

namespace App\Service\Backup\Pass;

/**
 * One feed's scalars for the entry and state phases, captured before the entity manager is cleared: its id, the
 * guid hash => entry id map, and acceptsNewEntries, false once any OTHER account subscribes. A restore never pushes
 * articles into a stranger's unread list, so a shared feed drops the file's entries and their states.
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
