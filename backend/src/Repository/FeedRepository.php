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
     * subscribed to; $force ignores the schedule but respects
     * $cooldownCutoff (feeds fetched after the cutoff are skipped).
     *
     * @return list<Feed>
     */
    public function findDue(
        \DateTimeImmutable $now,
        int $limit,
        ?int $userId = null,
        ?int $feedId = null,
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): array {
        /** @var list<Feed> $feeds */
        $feeds = $this->dueQueryBuilder($now, $userId, $feedId, $force, $cooldownCutoff)
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
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): int {
        return (int) $this->dueQueryBuilder($now, $userId, $feedId, $force, $cooldownCutoff)
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function dueQueryBuilder(
        \DateTimeImmutable $now,
        ?int $userId,
        ?int $feedId,
        bool $force,
        ?\DateTimeImmutable $cooldownCutoff,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('f');

        if ($feedId !== null) {
            return $qb->andWhere('f.id = :feedId')->setParameter('feedId', $feedId);
        }

        $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

        if ($force) {
            if ($cooldownCutoff !== null) {
                $qb->andWhere('(f.lastFetchedAt IS NULL OR f.lastFetchedAt <= :cooldownCutoff)')
                    ->setParameter('cooldownCutoff', $cooldownCutoff);
            }
        } else {
            $qb->andWhere('(f.nextFetchAt IS NULL OR f.nextFetchAt <= :now)')
                ->setParameter('now', $now);
        }

        if ($userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $userId);
        }

        return $qb;
    }
}
