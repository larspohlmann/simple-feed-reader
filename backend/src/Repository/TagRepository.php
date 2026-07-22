<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * The user's tags matching the given ids. Fewer results than ids means one
     * or more ids were invalid or belonged to another user.
     *
     * @param list<int> $ids
     *
     * @return list<Tag>
     */
    public function findAllByIdsForUser(array $ids, int $userId): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Tag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
