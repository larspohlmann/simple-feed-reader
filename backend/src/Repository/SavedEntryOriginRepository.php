<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryOrigin;
use App\Entity\SavedEntryOrigin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedEntryOrigin>
 */
final class SavedEntryOriginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedEntryOrigin::class);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, EntryOrigin> by entry id; an entry without an origin is absent
     */
    public function originsByEntryIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        /** @var list<array{entryId: int|string, title: ?string, faviconUrl: ?string}> $rows */
        $rows = $this->createQueryBuilder('origin')
            ->select('IDENTITY(origin.entry) AS entryId', 'origin.title', 'origin.faviconUrl')
            ->andWhere('origin.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getArrayResult();

        $origins = [];
        foreach ($rows as $row) {
            $origins[(int) $row['entryId']] = new EntryOrigin($row['title'], $row['faviconUrl']);
        }

        return $origins;
    }
}
