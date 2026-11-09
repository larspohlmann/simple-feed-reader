<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogFeed;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogFeed>
 */
final class CatalogFeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly NextPosition $nextPosition)
    {
        parent::__construct($registry, CatalogFeed::class);
    }

    public function getById(int $id): CatalogFeed
    {
        return $this->find($id) ?? throw new RecordNotFoundException('No such feed.');
    }

    /** @return list<CatalogFeed> every catalog feed in admin order, enabled or not */
    public function findAllOrdered(): array
    {
        /** @var list<CatalogFeed> $rows */
        $rows = $this->createQueryBuilder('f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
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

    /** Matches what the picker can show: an enabled feed in an enabled category. */
    public function hasPickableFeed(): bool
    {
        $pickable = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM catalog_feed feed'
            . ' INNER JOIN catalog_category category ON category.id = feed.category_id'
            . ' WHERE feed.enabled = :enabled AND category.enabled = :enabled)',
            ['enabled' => true],
        );

        \assert(is_numeric($pickable));

        return (bool) $pickable;
    }

    public function nextPositionInCategory(int $categoryId): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('f')->andWhere('f.category = :category')->setParameter('category', $categoryId),
        );
    }
}
