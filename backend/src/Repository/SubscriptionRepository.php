<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function existsForUserAndFeed(int $userId, int $feedId): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.feed = :feedId')->setParameter('feedId', $feedId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * A user's subscriptions with their feed and tags eager-loaded (no N+1),
     * ordered by creation time then id for a stable list.
     *
     * @return list<Subscription>
     */
    public function findForUserWithTags(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.tags', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneOwnedBy(int $id, int $userId): ?Subscription
    {
        /** @var Subscription|null $row */
        $row = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.tags', 't')->addSelect('t')
            ->andWhere('s.id = :id')->setParameter('id', $id)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * The user's subscriptions carrying a given tag (feed eager-loaded).
     *
     * @return list<Subscription>
     */
    public function findForUserByTagId(int $userId, int $tagId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->innerJoin('s.tags', 't')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('t.id = :tagId')->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Subscriptions carrying a given tag — used to detach the tag before it is
     * deleted (portable: does not rely on join-table FK cascade behaviour).
     *
     * @return list<Subscription>
     */
    public function findByTag(Tag $tag): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->innerJoin('s.tags', 't')
            ->andWhere('t = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
