<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Batch-loads, for a page of entry rows, the owned saved searches each entry
 * belongs to — one query, no N+1, exactly as EntryCategoryLoader does for
 * category labels. User-scoped, because membership is per user.
 */
final readonly class SavedSearchMembershipLoader
{
    public function __construct(private SavedSearchEntryRepository $memberships)
    {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows, int $userId): array
    {
        if ($rows === []) {
            return [];
        }

        $byEntryId = $this->memberships->savedSearchesByEntry($userId, EntryListRow::entryIdsOf($rows));

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $byEntryId),
            $rows,
        );
    }

    /**
     * @param array<int, list<array{id: int, slug: string, term: string}>> $byEntryId
     */
    private function enrich(EntryListRow $row, array $byEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $byEntryId),
            $row->duplicates,
        );

        return $row
            ->withDuplicates($enrichedDuplicates)
            ->withSavedSearches($byEntryId[$row->entry->getId()] ?? []);
    }
}
