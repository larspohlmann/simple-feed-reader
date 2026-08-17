<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\FeedStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feed>
 */
class FeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feed::class);
    }

    /**
     * Feeds eligible for refresh, never-fetched first, then most overdue.
     *
     * @return list<Feed>
     */
    public function findDue(DueFeedCriteria $criteria, int $limit): array
    {
        /** @var list<Feed> $feeds */
        $feeds = $this->dueQueryBuilder($criteria)
            ->addSelect('COALESCE(f.fetchSchedule.nextFetchAt, :epoch) AS HIDDEN dueOrder')
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('dueOrder', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $feeds;
    }

    public function countDue(DueFeedCriteria $criteria): int
    {
        return (int) $this->dueQueryBuilder($criteria)
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The URLs of the feeds this user is subscribed to, as a lookup set. The
     * catalog marks its entries `subscribed` by URL rather than by feed id
     * because a catalog row knows a URL, not which shared Feed row it became.
     *
     * @return array<string, true>
     */
    public function subscribedUrlSetForUser(int $userId): array
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.url AS url')
            ->join(Subscription::class, 's', 'ON', 's.feed = f AND s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['url']] = true;
        }

        return $set;
    }

    /**
     * The ids of the feeds this user subscribes to. Read before deleting the
     * account: once the subscription rows cascade away there is nothing left
     * to ask.
     *
     * @return list<int>
     */
    public function idsSubscribedByUser(int $userId): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('f.id AS id')
            ->join(Subscription::class, 's', 'ON', 's.feed = f AND s.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Whether any account OTHER than this one reads the feed. A feed row is
     * shared, and the restore asks this before it writes: one other subscriber
     * makes the feed's entries a stranger's unread list, so the restore
     * references the row and adds nothing to it.
     *
     * A question about the feed, answered here rather than on
     * SubscriptionRepository, and as a yes/no rather than a count — no caller
     * has any business with "how many strangers", and a bare number invites
     * exactly that.
     */
    public function isReadByAnotherUser(int $feedId, int $excludedUserId): bool
    {
        $others = (int) $this->createQueryBuilder('f')
            ->select('COUNT(s.id)')
            ->join(Subscription::class, 's', 'ON', 's.feed = f')
            ->andWhere('f.id = :feedId')->setParameter('feedId', $feedId)
            ->andWhere('s.user <> :userId')->setParameter('userId', $excludedUserId)
            ->getQuery()
            ->getSingleScalarResult();

        return $others > 0;
    }

    private function dueQueryBuilder(DueFeedCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('f');

        $this->restrictToSchedule($qb, $criteria);
        $this->restrictToOwnership($qb, $criteria);
        $this->restrictToUnhandled($qb, $criteria);

        return $qb;
    }

    private function restrictToSchedule(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->feedId !== null) {
            // Manual per-feed retry: exactly this feed, "gone" included, schedule
            // ignored — but the ownership scope still applies.
            $qb->andWhere('f.id = :feedId')->setParameter('feedId', $criteria->feedId);

            return;
        }

        $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

        if ($criteria->force) {
            // Force drops the schedule entirely; only the cooldown may hold a
            // feed back, and only when the caller set one.
            $this->restrictToCooldown($qb, $criteria);

            return;
        }

        $qb->andWhere('(f.fetchSchedule.nextFetchAt IS NULL OR f.fetchSchedule.nextFetchAt <= :now)')
            ->setParameter('now', $criteria->now);
    }

    private function restrictToCooldown(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->cooldownCutoff === null) {
            return;
        }

        // A lastFetchedAt in the future is impossible under a correct clock, so
        // something wrote it wrong — a worker on a non-UTC timezone did exactly
        // that in #151, before the kernel pinned UTC. Treat it as stale rather
        // than let it read as "just fetched" and freeze the feed out of every
        // refresh in silence, which is a costly failure to notice.
        $qb->andWhere(
            '(f.fetchSchedule.lastFetchedAt IS NULL'
            . ' OR f.fetchSchedule.lastFetchedAt <= :cooldownCutoff'
            . ' OR f.fetchSchedule.lastFetchedAt > :now)',
        )
            ->setParameter('cooldownCutoff', $criteria->cooldownCutoff)
            ->setParameter('now', $criteria->now);
    }

    private function restrictToOwnership(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $criteria->userId);
        }

        if ($criteria->tagId === null) {
            return;
        }

        // Scope to feeds the user has tagged with $tagId. Pinning the
        // subscription to :userId means another user's identically-named tag
        // can never widen the scope.
        $qb->andWhere(sprintf(
            'EXISTS (SELECT ts.id FROM %s ts JOIN ts.subscriptionTags tst '
            . 'WHERE ts.feed = f AND ts.user = :userId AND tst.tag = :tagId)',
            Subscription::class,
        ))->setParameter('tagId', $criteria->tagId);
    }

    private function restrictToUnhandled(QueryBuilder $qb, DueFeedCriteria $criteria): void
    {
        if ($criteria->excludedFeedIds === []) {
            return;
        }

        $qb->andWhere('f.id NOT IN (:excludedFeedIds)')
            ->setParameter('excludedFeedIds', $criteria->excludedFeedIds);
    }
}
