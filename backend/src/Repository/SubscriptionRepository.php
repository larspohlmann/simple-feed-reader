<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
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

    /**
     * The user's subscriptions matching the given ids. Fewer results than ids
     * means one or more ids were invalid or belonged to another user.
     *
     * @param list<int> $ids
     *
     * @return list<Subscription>
     */
    public function findAllByIdsForUser(array $ids, int $userId): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The next append position in the untagged "Feeds" list: one past the user's
     * current max (0 when they have none).
     */
    public function nextPositionForUser(int $userId): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    public function findOneOwnedBy(int $id, int $userId): ?Subscription
    {
        /** @var Subscription|null $row */
        $row = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.subscriptionTags', 'st')->addSelect('st')
            ->leftJoin('st.tag', 't')->addSelect('t')
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
            ->innerJoin('s.subscriptionTags', 'st')->innerJoin('st.tag', 't')
            ->andWhere('s.user = :user')->setParameter('user', $userId)
            ->andWhere('t.id = :tagId')->setParameter('tagId', $tagId)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Unread entry counts keyed by subscription id, in one query across all the
     * user's subscriptions. Unread = no explicit state and above the watermark,
     * OR an explicit isRead=false row. Subscriptions with zero unread are absent
     * from the map (the caller defaults them to 0).
     *
     * @return array<int, int>
     */
    public function unreadCountsForUser(int $userId): array
    {
        /** @var list<array{subscriptionId: int, unreadCount: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(sprintf(
            'SELECT s.id AS subscriptionId, COUNT(e.id) AS unreadCount
             FROM %s s
             JOIN %s e WITH e.feed = s.feed
             LEFT JOIN %s es WITH es.entry = e AND es.user = s.user
             WHERE s.user = :user AND (
                 es.isRead = :false
                 OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL
                     OR COALESCE(e.publishedAt, e.createdAt) > s.markedReadUntil))
             )
             GROUP BY s.id',
            Subscription::class,
            Entry::class,
            EntryState::class,
        ))
            ->setParameter('user', $userId)
            ->setParameter('false', false, Types::BOOLEAN)
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['subscriptionId']] = (int) $row['unreadCount'];
        }

        return $map;
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
            ->innerJoin('s.subscriptionTags', 'st')->innerJoin('st.tag', 't')
            ->andWhere('t = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
