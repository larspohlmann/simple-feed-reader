<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem;

use App\Entity\Entry;
use App\Repository\CanonicalItemRepository;

/** Links each unlinked entry a key rule claims to its canonical item, inserting the item when missing. The caller flushes. */
final readonly class CanonicalItemLinker
{
    public function __construct(
        private CanonicalItemRepository $canonicalItems,
        private CanonicalKeys $canonicalKeys,
    ) {
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int> the positions in $entries of the entries it linked
     */
    public function linkClaimed(array $entries): array
    {
        $itemIds = $this->ensureItemIds($entries);
        $items = $this->canonicalItems->findByIds(array_values(array_unique($itemIds)));
        foreach ($itemIds as $position => $itemId) {
            $entries[$position]->linkTo($items[$itemId]);
        }

        return array_keys($itemIds);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array<int, int> the item id of each unlinked entry a rule claims, by its position
     */
    private function ensureItemIds(array $entries): array
    {
        $itemIds = [];
        foreach ($entries as $position => $entry) {
            $key = $entry->getCanonicalItem() === null ? $this->canonicalKeys->findKey($entry) : null;
            if ($key !== null) {
                $itemIds[$position] = $this->canonicalItems->ensure($key);
            }
        }

        return $itemIds;
    }
}
