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
     * Scopes: $feedId selects exactly one feed (including "gone" ones — this
     * is the manual-retry path); $userId restricts to feeds the user is
     * subscribed to; $tagId further restricts to feeds the user has tagged with
     * it; $force ignores the schedule but respects $cooldownCutoff (feeds
     * fetched after the cutoff are skipped).
     *
     * @return list<Feed>
     */
    public function findDue(
        \DateTimeImmutable $now,
        int $limit,
        ?int $userId = null,
        ?int $feedId = null,
        ?int $tagId = null,
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): array {
        /** @var list<Feed> $feeds */
        $feeds = $this->dueQueryBuilder($now, $userId, $feedId, $tagId, $force, $cooldownCutoff)
            ->addSelect('COALESCE(f.nextFetchAt, :epoch) AS HIDDEN dueOrder')
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('dueOrder', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $feeds;
    }

    public function countDue(
        \DateTimeImmutable $now,
        ?int $userId = null,
        ?int $feedId = null,
        ?int $tagId = null,
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): int {
        return (int) $this->dueQueryBuilder($now, $userId, $feedId, $tagId, $force, $cooldownCutoff)
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

    private function dueQueryBuilder(
        \DateTimeImmutable $now,
        ?int $userId,
        ?int $feedId,
        ?int $tagId,
        bool $force,
        ?\DateTimeImmutable $cooldownCutoff,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('f');

        if ($feedId !== null) {
            // Manual per-feed retry: exactly this feed, "gone" included, schedule
            // ignored — but the user scope below still applies.
            $qb->andWhere('f.id = :feedId')->setParameter('feedId', $feedId);
        } else {
            $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

            if ($force) {
                if ($cooldownCutoff !== null) {
                    // A lastFetchedAt in the future is impossible under a correct
                    // clock: a skewed process clock (observed on the FastCGI host)
                    // stamped it there. Treat it as stale rather than let it read
                    // as "just fetched" and freeze the feed out of every refresh.
                    $qb->andWhere(
                        '(f.lastFetchedAt IS NULL OR f.lastFetchedAt <= :cooldownCutoff OR f.lastFetchedAt > :now)',
                    )
                        ->setParameter('cooldownCutoff', $cooldownCutoff)
                        ->setParameter('now', $now);
                }
            } else {
                $qb->andWhere('(f.nextFetchAt IS NULL OR f.nextFetchAt <= :now)')
                    ->setParameter('now', $now);
            }
        }

        if ($userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $userId);
        }

        if ($tagId !== null) {
            // Scope to feeds the user has tagged with $tagId. Pinning the
            // subscription to :userId means another user's identically-named tag
            // can never widen the scope.
            $qb->andWhere(sprintf(
                'EXISTS (SELECT ts.id FROM %s ts JOIN ts.subscriptionTags tst '
                . 'WHERE ts.feed = f AND ts.user = :userId AND tst.tag = :tagId)',
                Subscription::class,
            ))->setParameter('tagId', $tagId);
        }

        return $qb;
    }
}
