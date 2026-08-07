<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryState>
 */
class EntryStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryState::class);
    }

    public function findOneForUserEntry(int $userId, int $entryId): ?EntryState
    {
        /** @var EntryState|null $row */
        $row = $this->createQueryBuilder('es')
            ->andWhere('IDENTITY(es.user) = :user')->setParameter('user', $userId)
            ->andWhere('IDENTITY(es.entry) = :entry')->setParameter('entry', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * The mark-all-read watermark of the subscription the entry belongs to, or
     * null when there is none (or the user does not subscribe to the feed). An
     * entry at or below it is read even with no EntryState row of its own.
     *
     * Lives here for the same reason unreadCountsForUser() does: its subject is
     * read state, not the subscription that happens to carry the column.
     */
    public function markedReadUntilForUserEntry(int $userId, int $entryId): ?\DateTimeImmutable
    {
        /** @var array{markedReadUntil: \DateTimeImmutable|null}|null $row */
        $row = $this->getEntityManager()->createQuery(sprintf(
            'SELECT s.markedReadUntil AS markedReadUntil
             FROM %s s
             JOIN %s e ON e.feed = s.feed
             WHERE s.user = :user AND e.id = :entry',
            Subscription::class,
            Entry::class,
        ))
            ->setParameter('user', $userId)
            ->setParameter('entry', $entryId)
            ->getOneOrNullResult();

        return $row['markedReadUntil'] ?? null;
    }

    /**
     * Total favourite and kept entries for the user, counting only entries whose
     * feed the user still subscribes to — the same subscription gate the
     * Favorites/Kept lists apply, so the sidebar badges match their lists (an
     * orphaned state left behind by an unsubscribe is not counted).
     *
     * @return array{favorites: int, kept: int}
     */
    public function favoriteAndKeptCountsForUser(int $userId): array
    {
        /** @var array{favorites: int|string, kept: int|string} $row */
        $row = $this->createQueryBuilder('es')
            ->select('SUM(CASE WHEN es.isFavorite = :true THEN 1 ELSE 0 END) AS favorites')
            ->addSelect('SUM(CASE WHEN es.isKept = :true THEN 1 ELSE 0 END) AS kept')
            ->join('es.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN)
            ->getQuery()
            ->getSingleResult();

        return [
            'favorites' => (int) $row['favorites'],
            'kept' => (int) $row['kept'],
        ];
    }

    /**
     * Unread entry counts keyed by subscription id, in one query across all the
     * user's subscriptions. Unread = no explicit state and above the watermark,
     * OR an explicit isRead=false row. Subscriptions with zero unread are absent
     * from the map (the caller defaults them to 0).
     *
     * Lives here rather than on SubscriptionRepository because its subject is
     * read state, not the subscription itself — it happens to be rooted on
     * Subscription with EntryState LEFT JOINed in (the opposite shape from
     * favoriteAndKeptCountsForUser() above, which is why it cannot be built
     * with $this->createQueryBuilder() the way that method is).
     *
     * @return array<int, int>
     */
    public function unreadCountsForUser(int $userId): array
    {
        /** @var list<array{subscriptionId: int, unreadCount: int}> $rows */
        $rows = $this->getEntityManager()->createQuery(sprintf(
            'SELECT s.id AS subscriptionId, COUNT(e.id) AS unreadCount
             FROM %s s
             JOIN %s e ON e.feed = s.feed
             LEFT JOIN %s es ON es.entry = e AND es.user = s.user
             WHERE s.user = :user AND (
                 es.isRead = :false
                 OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL
                     OR e.effectiveDate > s.markedReadUntil))
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
}
