<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Repository\CanonicalItemRepository;
use App\Service\Foundation\CanonicalItem\CanonicalKeys;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** Links each entry a key rule claims to its canonical item, then records the fetched counts there. The caller flushes. */
final readonly class CanonicalItemWriter
{
    public function __construct(
        private CanonicalItemRepository $canonicalItems,
        private CanonicalKeys $canonicalKeys,
    ) {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs new entries, or stored ones a refetch served again */
    public function attach(array $pairs): void
    {
        $linking = $this->ensureItems($pairs);
        if ($linking === []) {
            return;
        }
        $items = $this->canonicalItems->findByIds(array_values(array_unique(array_column($linking, 2))));
        foreach ($linking as [$entry, $parsed, $itemId]) {
            $entry->linkTo($items[$itemId]);
            $entry->discussionCounts()->recordCounts($parsed->discussionCounts);
        }
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs
     *
     * @return list<array{0: Entry, 1: ParsedEntryModel, 2: int}> the unlinked entries a rule claims, with their item id
     */
    private function ensureItems(array $pairs): array
    {
        $linking = [];
        foreach ($pairs as [$entry, $parsed]) {
            $key = $entry->getCanonicalItem() === null ? $this->canonicalKeys->findKey($entry) : null;
            if ($key !== null) {
                $linking[] = [$entry, $parsed, $this->canonicalItems->ensure($key)];
            }
        }

        return $linking;
    }
}
