<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LinkCard;
use App\Entity\LinkCardContent;

/** Batch-loads the link cards of a page of entry rows, duplicates included, in one query. */
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

        $cardsByEntryId = $this->linkCards->findByEntryIds($this->entryIdsOf($rows));

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $cardsByEntryId),
            $rows,
        );
    }

    /**
     * @param array<int, list<LinkCard>> $cardsByEntryId
     */
    private function enrich(EntryListRow $row, array $cardsByEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $cardsByEntryId),
            $row->duplicates,
        );
        $contents = array_map(
            static fn (LinkCard $card): LinkCardContent => $card->getContent(),
            $cardsByEntryId[$row->entry->getId()] ?? [],
        );

        return $row->withDuplicates($enrichedDuplicates)->withLinkCards($contents);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private function entryIdsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                $id = $member->entry->getId();
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }
}
