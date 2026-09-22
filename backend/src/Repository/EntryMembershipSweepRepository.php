<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The two entry-side reads the saved-search membership sweep needs (#1116):
 * the settled ceiling it must not walk past, and the ascending id walk
 * between a search's mark and that ceiling. Split out of EntryRepository so
 * that class's existence/lookup/keyset-walk surface stays readable rather
 * than growing a fourth, unrelated responsibility (PHPMD TooManyPublicMethods).
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class EntryMembershipSweepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * The highest entry id created no later than $createdNoLaterThan — the
     * membership sweep's ceiling, so entries the engine may not have indexed
     * yet wait for the next run (#1116).
     */
    public function settledCeilingId(\DateTimeImmutable $createdNoLaterThan): int
    {
        $ceiling = $this->createQueryBuilder('e')
            ->select('MAX(e.id)')
            ->andWhere('e.createdAt <= :createdNoLaterThan')
            ->setParameter('createdNoLaterThan', $createdNoLaterThan)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $ceiling;
    }

    /**
     * @return list<int> ascending ids in ($afterId, $upToId], at most $limit
     */
    public function idsBetween(int $afterId, int $upToId, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id > :afterId')
            ->andWhere('e.id <= :upToId')
            ->setParameter('afterId', $afterId)
            ->setParameter('upToId', $upToId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
