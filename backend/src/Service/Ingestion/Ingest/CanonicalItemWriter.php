<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** Links each entry a key rule claims to its canonical item, then records the fetched counts and the cross-post hash there. The caller flushes. */
final readonly class CanonicalItemWriter
{
    public function __construct(
        private CanonicalItemLinker $linker,
        private CrossPostKeys $crossPostKeys,
    ) {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs new entries, or stored ones a refetch served again */
    public function attach(array $pairs): void
    {
        foreach ($this->linker->linkClaimed(array_column($pairs, 0)) as $position) {
            [$entry, $parsed] = $pairs[$position];
            $entry->selectDiscussionCounts()->recordCounts($parsed->discussionCounts);
            $this->recordCrossPostHash($entry, $parsed);
        }
    }

    private function recordCrossPostHash(Entry $entry, ParsedEntryModel $parsed): void
    {
        $item = $entry->getCanonicalItem();
        if ($item === null || $item->getCrossPostHash() !== null) {
            return;
        }
        $hash = $this->crossPostKeys->findHash($item, $parsed);
        if ($hash !== null) {
            $item->recordCrossPostHash($hash);
        }
    }
}
