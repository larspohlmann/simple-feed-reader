<?php

declare(strict_types=1);

namespace App\Repository;

/** Gives a page of rows their categories, saved searches, link cards and pending flag: one query per lookup. */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryRepository $categories,
        private SavedSearchEntryRepository $memberships,
        private LinkCardRepository $linkCards,
        private ShareEnrichmentRepository $shareEnrichments,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        $enrichments = $this->enrichmentsByEntryId($rows, $userId);
        $enrich = static fn (EntryListRow $member): EntryListRow => $member->withEnrichment(
            $enrichments[$member->entry->requireId()],
        );

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->mapWithDuplicates($enrich), $rows);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, EntryListRowEnrichment>
     */
    private function enrichmentsByEntryId(array $rows, int $userId): array
    {
        $entryIds = EntryListRow::entryIdsOf($rows);
        $categories = $this->categories->labelsByEntryIds($entryIds);
        $savedSearches = $this->memberships->savedSearchesByEntry($userId, $entryIds);
        $linkCards = $this->linkCards->contentsByEntryIds($entryIds);
        $pendingIds = array_flip($this->shareEnrichments->pendingEntryIdsAmong(self::topLevelEntryIdsOf($rows)));

        $enrichments = [];
        foreach ($entryIds as $entryId) {
            $enrichments[$entryId] = new EntryListRowEnrichment(
                $categories[$entryId] ?? [],
                $savedSearches[$entryId] ?? [],
                $linkCards[$entryId] ?? [],
                isset($pendingIds[$entryId]),
            );
        }

        return $enrichments;
    }

    /**
     * Only a top-level row can be pending, never one of its duplicates.
     *
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function topLevelEntryIdsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
    }
}
