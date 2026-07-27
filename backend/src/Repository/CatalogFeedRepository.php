<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogFeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogFeed>
 */
class CatalogFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogFeed::class);
    }

    /**
     * The enabled feeds matching these ids. Fewer results than ids means one or
     * more ids were unknown or disabled — which the subscribe path IGNORES
     * rather than rejecting, so a stale picker never fails the whole submit.
     *
     * @param list<int> $ids
     *
     * @return list<CatalogFeed>
     */
    public function findEnabledByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<CatalogFeed> $rows */
        $rows = $this->createQueryBuilder('f')
            ->innerJoin('f.category', 'c')->addSelect('c')
            ->andWhere('f.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('f.enabled = true')
            ->andWhere('c.enabled = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The warm queue: enabled feeds with no cached icon, or one older than
     * $staleBefore, skipping rows whose last failure is newer than $retryBefore
     * so a dead icon is not re-attempted on every deploy.
     *
     * @return list<CatalogFeed>
     */
    public function findNeedingFavicon(
        \DateTimeImmutable $staleBefore,
        \DateTimeImmutable $retryBefore,
        ?int $limit,
    ): array {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.enabled = true')
            ->andWhere('f.faviconFetchedAt IS NULL OR f.faviconFetchedAt < :stale')
            ->setParameter('stale', $staleBefore)
            ->andWhere('f.faviconFailedAt IS NULL OR f.faviconFailedAt < :retry')
            ->setParameter('retry', $retryBefore)
            ->orderBy('f.id', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<CatalogFeed> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * How many rows still want an icon — what a polling caller stops on.
     */
    public function countNeedingFavicon(\DateTimeImmutable $staleBefore, \DateTimeImmutable $retryBefore): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.enabled = true')
            ->andWhere('f.faviconFetchedAt IS NULL OR f.faviconFetchedAt < :stale')
            ->setParameter('stale', $staleBefore)
            ->andWhere('f.faviconFailedAt IS NULL OR f.faviconFailedAt < :retry')
            ->setParameter('retry', $retryBefore)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marks every enabled row as needing a fresh icon — the --force path.
     * A bulk UPDATE, run ONCE before a force warm; the normal window then lets
     * each row drop out of the due set as it is (re-)warmed, so the loop converges.
     *
     * @return int rows affected
     */
    public function resetFaviconFreshness(): int
    {
        $affected = $this->createQueryBuilder('f')
            ->update()
            ->set('f.faviconFetchedAt', 'NULL')
            ->set('f.faviconFailedAt', 'NULL')
            ->andWhere('f.enabled = true')
            ->getQuery()
            ->execute();

        // A DQL UPDATE returns its affected-row count, but Doctrine types
        // execute() as mixed; narrow it rather than blind-casting.
        return \is_int($affected) ? $affected : 0;
    }

    public function nextPositionInCategory(int $categoryId): int
    {
        $max = $this->createQueryBuilder('f')
            ->select('MAX(f.position)')
            ->andWhere('f.category = :category')->setParameter('category', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
