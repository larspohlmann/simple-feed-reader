<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batch-loads the feed-declared category labels for a page of entry rows in a
 * single query, so the list, single-entry and recommendation responses can
 * carry them without an N+1. Labels come back in the feed-declared order
 * (entry_category.position).
 */
final readonly class EntryCategoryLoader
{
    public function __construct(private EntityManagerInterface $em)
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

        $labelsByEntryId = $this->labelsFor($this->entryIdsOf($rows));

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $labelsByEntryId),
            $rows,
        );
    }

    /**
     * @param array<int, list<string>> $labelsByEntryId
     */
    private function enrich(EntryListRow $row, array $labelsByEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $labelsByEntryId),
            $row->duplicates,
        );

        return $row
            ->withDuplicates($enrichedDuplicates)
            ->withCategories($labelsByEntryId[$row->entry->getId()] ?? []);
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
            $id = $row->entry->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
            foreach ($row->duplicates as $duplicate) {
                $duplicateId = $duplicate->entry->getId();
                if ($duplicateId !== null) {
                    $ids[$duplicateId] = $duplicateId;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<string>>
     */
    private function labelsFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int, label: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ec.entry) AS entryId', 'ec.label AS label')
            ->from(EntryCategory::class, 'ec')
            ->where('ec.entry IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->orderBy('ec.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int) $row['entryId']][] = $row['label'];
        }

        return $byEntry;
    }
}
