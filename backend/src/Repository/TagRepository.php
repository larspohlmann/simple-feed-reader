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

    /**
     * The user's tags, in their custom order (name as a stable tiebreak).
     *
     * @return list<Tag>
     */
    public function findForUser(int $userId): array
    {
        /** @var list<Tag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The next append position for a new tag: one past the user's current max
     * (0 when they have none).
     */
    public function nextPositionForUser(int $userId): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.position)')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /**
     * Whether the user already has a tag with this name (case-insensitive).
     * $excludeId lets a rename skip the tag being renamed.
     */
    public function existsForUserAndName(int $userId, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->andWhere('LOWER(t.name) = LOWER(:name)')->setParameter('name', $name);

        if (null !== $excludeId) {
            $qb->andWhere('t.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneOwnedBy(int $id, int $userId): ?Tag
    {
        /** @var Tag|null $row */
        $row = $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')->setParameter('id', $id)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * The user's tag with this name (case-insensitive), or null. Consumed by
     * OPML import to reuse an existing tag rather than creating a duplicate.
     */
    public function findOneByNameForUser(int $userId, string $name): ?Tag
    {
        /** @var Tag|null $tag */
        $tag = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')->setParameter('user', $userId)
            ->andWhere('LOWER(t.name) = LOWER(:name)')->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $tag;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many tags each of the given users owns, in ONE query. A user with no
     * tags is absent from the result, not zero-valued — callers default a miss
     * to 0. See SubscriptionRepository::countsByUserIds() for why this is
     * batched and query-count tested.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function countsByUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.user) AS userId', 'COUNT(t.id) AS total')
            ->andWhere('t.user IN (:userIds)')->setParameter('userIds', $userIds)
            ->groupBy('t.user')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['userId']] = (int) $row['total'];
        }

        return $counts;
    }
}
