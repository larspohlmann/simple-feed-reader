<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogCategory>
 */
class CatalogCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogCategory::class);
    }

    /**
     * Enabled categories in display order, with their feeds already loaded — the
     * picker renders every category at once, so a lazy collection here would be
     * 13 extra queries per page load.
     *
     * @return list<CatalogCategory>
     */
    public function findEnabledWithFeeds(): array
    {
        /** @var list<CatalogCategory> $rows */
        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.feeds', 'f')->addSelect('f')
            ->andWhere('c.enabled = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Every category in admin order, enabled or not.
     *
     * @return list<CatalogCategory>
     */
    public function findAllOrdered(): array
    {
        /** @var list<CatalogCategory> $rows */
        $rows = $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
