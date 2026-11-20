<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

/** Links each entry a key rule claims to its canonical item, then records the fetched counts there. The caller flushes. */
final readonly class CanonicalItemWriter
{
    public function __construct(
        private CanonicalItemLinker $linker,
    ) {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs new entries, or stored ones a refetch served again */
    public function attach(array $pairs): void
    {
        foreach ($this->linker->linkClaimed(array_column($pairs, 0)) as $position) {
            [$entry, $parsed] = $pairs[$position];
            $entry->selectDiscussionCounts()->recordCounts($parsed->discussionCounts);
        }
    }
}
