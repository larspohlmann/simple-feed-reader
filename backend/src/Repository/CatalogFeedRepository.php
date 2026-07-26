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
