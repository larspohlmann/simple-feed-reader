<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem;

use App\Entity\Entry;
use App\Repository\CanonicalItemRepository;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\Clock\NaiveUtcClock;

/** Links each unlinked entry a key rule claims to its canonical item, inserting the item when missing. The caller flushes. */
final readonly class CanonicalItemLinker
{
    public function __construct(
        private CanonicalItemRepository $canonicalItems,
        private CanonicalKeys $canonicalKeys,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int> the positions in $entries of the entries it linked
     */
    public function linkClaimed(array $entries): array
    {
        $keys = $this->collectClaimedKeys($entries);
        $items = $this->canonicalItems->ensureAll(array_values($keys), $this->clock->now());
        foreach ($keys as $position => $key) {
            $entries[$position]->linkTo($items[$key->identity()]);
        }

        return array_keys($keys);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array<int, CanonicalKeyModel> the key of each unlinked entry a rule claims, by its position
     */
    private function collectClaimedKeys(array $entries): array
    {
        $keys = [];
        foreach ($entries as $position => $entry) {
            $key = $entry->getCanonicalItem() === null ? $this->canonicalKeys->findKey($entry) : null;
            if ($key !== null) {
                $keys[$position] = $key;
            }
        }

        return $keys;
    }
}
