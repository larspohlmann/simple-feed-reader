<?php

declare(strict_types=1);

namespace App\Repository;

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

        $withCards = static fn (EntryListRow $row): EntryListRow => $row->withLinkCards(
            $cardsByEntryId[$row->entry->getId()] ?? [],
        );

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->mapWithDuplicates($withCards), $rows);
    }
}
