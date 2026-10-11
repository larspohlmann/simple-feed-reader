<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkCardContent;

final readonly class LinkCardLoader
{
    public function __construct(private LinkCardRepository $linkCards)
    {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $cardsByEntryId = $this->linkCards->contentsByEntryIds(EntryListRow::entryIdsOf($rows));

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $cardsByEntryId),
            $rows,
        );
    }

    /** @param array<int, list<LinkCardContent>> $cardsByEntryId */
    private function enrich(EntryListRow $row, array $cardsByEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $cardsByEntryId),
            $row->duplicates,
        );

        return $row->withDuplicates($enrichedDuplicates)->withLinkCards($cardsByEntryId[$row->entry->getId()] ?? []);
    }
}
