<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Select ids, then delete by id: portable across both suite dialects, unlike a DELETE with a subquery. */
final readonly class RowIds
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<int> */
    public function selectedBy(QueryBuilder $query): array
    {
        /** @var list<int> $ids */
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');

        return $ids;
    }

    /**
     * @param class-string $entityClass
     * @param list<int>    $ids
     */
    public function delete(string $entityClass, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->entityManager
            ->createQuery(sprintf('DELETE FROM %s doomed WHERE doomed.id IN (:ids)', $entityClass))
            ->setParameter('ids', $ids)
            ->execute();
    }
}
