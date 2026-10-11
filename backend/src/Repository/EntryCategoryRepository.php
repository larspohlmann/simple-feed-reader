<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryCategory> */
final class EntryCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryCategory::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<string>> the feed-declared labels in declared order, by entry id
     */
    public function labelsByEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int|string, label: string}> $rows */
        $rows = $this->createQueryBuilder('entryCategory')
            ->select('IDENTITY(entryCategory.entry) AS entryId', 'entryCategory.label AS label')
            ->where('entryCategory.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('entryCategory.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $labelsByEntryId = [];
        foreach ($rows as $row) {
            $labelsByEntryId[(int) $row['entryId']][] = $row['label'];
        }

        return $labelsByEntryId;
    }
}
