<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrphanedFeedRepository;

/**
 * The only place an unsubscribed feed is deleted, so the immediate path and the sweep cannot drift apart.
 * Bulk DQL bypasses the unit of work: a Feed the caller still holds is stale afterwards, so pass an id.
 */
final readonly class OrphanedFeedReclaimer
{
    /** Same chunking as EntryPruner: keeps the IN() list off the parameter limit. */
    private const int DELETE_CHUNK_SIZE = 500;

    public function __construct(private OrphanedFeedRepository $orphans)
    {
    }

    /** True when the feed had no subscriber left and was deleted. */
    public function reclaim(int $feedId): bool
    {
        return $this->deleteOrphans([$feedId]) > 0;
    }

    /** The safety net: every orphan currently in the database. */
    public function reclaimAll(): int
    {
        return $this->deleteOrphans($this->orphans->orphanIds());
    }

    /**
     * @param list<int> $feedIds
     */
    private function deleteOrphans(array $feedIds): int
    {
        if ([] === $feedIds) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($feedIds, self::DELETE_CHUNK_SIZE) as $chunk) {
            $deleted += $this->orphans->deleteOrphansAmong($chunk);
        }

        return $deleted;
    }
}
