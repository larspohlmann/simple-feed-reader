<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
     * Fetch by id or fail with a 404. Throwing the HTTP exception here keeps the
     * lookup-or-404 guard out of every admin controller that needs it, including
     * the reorder paths that look ids up from the request body, not the route.
     */
    public function getById(int $id): CatalogCategory
    {
        return $this->find($id) ?? throw new NotFoundHttpException('No such category.');
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

    /**
     * Whether the catalog holds nothing at all. Categories answer it for the
     * whole catalog: a feed cannot exist without the category it belongs to,
     * so no category means no feed either.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->count();
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
