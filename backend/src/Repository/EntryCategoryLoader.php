<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batch-loads feed-declared category labels for a page of entry rows in one
 * query, avoiding an N+1 across list, single-entry and recommendation
 * responses. Labels come back in feed-declared order (entry_category.position).
 */
final readonly class EntryCategoryLoader
{
    public function __construct(private EntityManagerInterface $entityManager)
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

        $labelsByEntryId = $this->labelsFor(EntryListRow::entryIdsOf($rows));

        $withLabels = static fn (EntryListRow $row): EntryListRow => $row->withCategories(
            $labelsByEntryId[$row->entry->getId()] ?? [],
        );

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->mapWithDuplicates($withLabels), $rows);
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
        $rows = $this->entityManager->createQueryBuilder()
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
