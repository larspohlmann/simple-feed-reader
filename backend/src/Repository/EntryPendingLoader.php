<?php

declare(strict_types=1);

namespace App\Repository;

/** Flags the rows whose embed or link cards are still to come. Only a top-level row is flagged, never a duplicate. */
final readonly class EntryPendingLoader
{
    public function __construct(private ShareEnrichmentRepository $enrichments)
    {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows): array
    {
        $pendingIds = array_flip($this->enrichments->pendingEntryIdsAmong(
            array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows),
        ));

        return array_map(
            static fn (EntryListRow $row): EntryListRow => $row->withPending(
                isset($pendingIds[$row->entry->requireId()]),
            ),
            $rows,
        );
    }
}
