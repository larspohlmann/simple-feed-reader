<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The membership sweep's two entry-side reads (#1116): the settled ceiling it
 * must not walk past, and the ascending id walk up to it.
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
     * The highest entry id created no later than $createdNoLaterThan, so
     * entries an engine may not have indexed yet wait for the next run.
     * Read backwards along the primary key rather than as MAX(): no index
     * leads on created_at, and the settled rows are all but the newest few.
     */
    public function settledCeilingId(\DateTimeImmutable $createdNoLaterThan): int
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.createdAt <= :createdNoLaterThan')
            ->setParameter('createdNoLaterThan', $createdNoLaterThan)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult();

        return (int) ($rows[0]['id'] ?? 0);
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
