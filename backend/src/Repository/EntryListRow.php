<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/** One row of the entry list: the shared Entry plus the caller's subscription to it and marks on it. */
final readonly class EntryListRow
{
    public function __construct(
        public Entry $entry,
        public EntryListRowSubscription $subscription,
        public EntryListRowMarks $marks,
        /**
         * The subscription's watermark, already folded into marks->isHidden; carried so EntryStateResolver can record
         * when it hid the entry.
         */
        public ?\DateTimeImmutable $markedReadUntil,
        /** @var list<self> */
        public array $duplicates = [],
        public EntryListRowEnrichment $enrichment = new EntryListRowEnrichment(),
    ) {
    }

    /**
     * @param list<self> $duplicates the in-scope copies this row collapsed
     */
    public function withDuplicates(array $duplicates): self
    {
        return $this->copyWith($duplicates, $this->enrichment);
    }

    /**
     * This row and each of its duplicates, given the enrichment of its own entry.
     *
     * @param array<int, EntryListRowEnrichment> $enrichmentsByEntryId
     */
    public function enrichedFrom(array $enrichmentsByEntryId): self
    {
        return $this->copyWith(
            array_map(
                static fn (self $duplicate): self => $duplicate->enrichedFrom($enrichmentsByEntryId),
                $this->duplicates,
            ),
            $enrichmentsByEntryId[$this->entry->requireId()],
        );
    }

    /**
     * @param list<self> $duplicates
     */
    private function copyWith(array $duplicates, EntryListRowEnrichment $enrichment): self
    {
        return clone($this, ['duplicates' => $duplicates, 'enrichment' => $enrichment]);
    }
}
