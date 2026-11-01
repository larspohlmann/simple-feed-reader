<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Repository\PostRemovalRepository;
use App\Repository\StoredPostCopy;
use App\Service\Search\EntryIndexer;

/** Removes a post its network no longer has: every stored copy, each kept from being stored again for a while. */
final readonly class LocalPostRemoval
{
    public function __construct(
        private PostRemovalRepository $removals,
        private DeletedPostTombstones $tombstones,
        private EntryIndexer $indexer,
        private SocialCountsCache $countsCache,
    ) {
    }

    public function remove(Entry $entry): void
    {
        $copies = $this->removals->copiesOf($entry);
        foreach ($copies as $copy) {
            $this->tombstones->bury($copy->feedId, $copy->guidHash);
        }
        $this->countsCache->forgetEntry($entry);
        $entryIds = array_map(static fn (StoredPostCopy $copy): int => $copy->entryId, $copies);
        $this->removals->deleteEntries($entryIds);
        $this->indexer->forget($entryIds);
    }
}
