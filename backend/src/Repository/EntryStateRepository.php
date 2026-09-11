<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryState>
 */
class EntryStateRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DuplicateCollapseDql $collapse,
    ) {
        parent::__construct($registry, EntryState::class);
    }

    /**
     * How many state rows the user owns. EntryState has no scalar id — its
     * primary key is the (user, entry) pair — so the count goes through the
     * entry association rather than an id column.
     */
    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.entry)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Idempotent insert of the (user, entry) state row, seeded for read state:
     * one racing writer wins, the other's INSERT is ignored rather than dying on
     * the duplicate primary key, and an existing row keeps its flags.
     */
    public function ensureRow(int $userId, int $entryId, bool $seedHidden, ?\DateTimeImmutable $seedHiddenAt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $isMysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $conflictClause = $isMysql ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_favorite, is_kept, is_viewed, viewed_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $conflictClause,
            ),
            [$userId, $entryId, $seedHidden, $seedHiddenAt, false, false, false, null],
            [
                Types::INTEGER,
                Types::INTEGER,
                Types::BOOLEAN,
                Types::DATETIME_IMMUTABLE,
                Types::BOOLEAN,
                Types::BOOLEAN,
                Types::BOOLEAN,
                Types::DATETIME_IMMUTABLE,
            ],
        );
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
     * One user's states in ascending entry-id slices — the backup's keyset walk.
     * Entry and feed ride along eagerly: the serialiser needs guidHash and the
     * feed URL per row, and a lazy load would cost two queries per state.
     *
     * Scoped to feeds the user still subscribes to, the same gate
     * stateCountsForUser() applies. A state an unsubscribe left behind
     * (SubscriptionService::unsubscribe drops the subscription but not
     * entry_state) names a feed/entry the export's feed and entry lines never
     * emit, so including it would leave the restore an orphaned line and a
     * footer count higher than what is restorable.
     *
     * @return list<EntryState>
     */
    public function forUserAfterEntryId(int $userId, int $afterEntryId, int $limit): array
    {
        /** @var list<EntryState> $states */
        $states = $this->createQueryBuilder('s')
            ->addSelect('e', 'f')
            ->join('s.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 'sub', 'ON', 'sub.feed = e.feed AND sub.user = :userId')
            ->andWhere('s.user = :userId')
            ->andWhere('e.id > :afterEntryId')
            ->setParameter('userId', $userId)
            ->setParameter('afterEntryId', $afterEntryId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $states;
    }

    /**
     * Total favourite, kept and viewed entries for the user, counting only
     * entries whose feed the user still subscribes to — the same subscription
     * gate the Favorites/Kept/Recently-read lists apply, so the sidebar badges
     * match their lists (an orphaned state left behind by an unsubscribe is not
     * counted).
     *
     * @return array{favorites: int, kept: int, viewed: int}
     */
    public function stateCountsForUser(int $userId): array
    {
        /** @var array{favorites: int|string, kept: int|string, viewed: int|string} $row */
        $row = $this->createQueryBuilder('es')
            ->select('SUM(CASE WHEN es.isFavorite = :true THEN 1 ELSE 0 END) AS favorites')
            ->addSelect('SUM(CASE WHEN es.isKept = :true THEN 1 ELSE 0 END) AS kept')
            ->addSelect('SUM(CASE WHEN es.isViewed = :true THEN 1 ELSE 0 END) AS viewed')
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
            'viewed' => (int) $row['viewed'],
        ];
    }

    /**
     * The instants at which the user opened an article at or after $sinceUtc,
     * one per open, for the reading-activity chart (#896). Scalars rather than
     * entities, and gated to feeds the user still subscribes to — the same gate
     * stateCountsForUser() applies, so the chart counts what the "Read" total
     * counts. Bucketing into days is left to the caller: viewedAt is naive UTC
     * and the buckets are cut in the viewer's zone, which no portable DQL
     * expression can shift before grouping.
     *
     * @return list<\DateTimeImmutable>
     */
    public function viewedAtSince(int $userId, \DateTimeImmutable $sinceUtc): array
    {
        /** @var list<array{viewedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('es')
            ->select('es.viewedAt AS viewedAt')
            ->join('es.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->andWhere('es.viewedAt >= :since')
            ->setParameter('user', $userId)
            ->setParameter('since', $sinceUtc)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): \DateTimeImmutable => $row['viewedAt'], $rows);
    }

    /**
     * The feeds the user has opened the most articles from, busiest first, for
     * the About page's "Top feeds by read" chart (#896). One row per feed with
     * its read total, gated to feeds still subscribed to and capped at $limit.
     * Only the feed id and count travel: the title is resolved on the client
     * from the subscription list it already holds, so it stays the custom title
     * the sidebar shows.
     *
     * @return list<array{feedId: int, readCount: int}>
     */
    public function readCountsByFeed(int $userId, int $limit): array
    {
        /** @var list<array{feedId: int, readCount: int}> $rows */
        $rows = $this->createQueryBuilder('es')
            ->select('f.id AS feedId', 'COUNT(es.entry) AS readCount')
            ->join('es.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->andWhere('es.isViewed = :viewed')
            ->setParameter('user', $userId)
            ->setParameter('viewed', true, Types::BOOLEAN)
            ->groupBy('f.id')
            ->orderBy('readCount', 'DESC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'feedId' => (int) $row['feedId'],
                'readCount' => (int) $row['readCount'],
            ],
            $rows,
        );
    }

    /**
     * Which of the given entry ids already have a state row for this user.
     *
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function entryIdsWithStateForUser(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int}> $rows */
        $rows = $this->createQueryBuilder('es')
            ->select('IDENTITY(es.entry) AS entryId')
            ->andWhere('IDENTITY(es.user) = :user')->setParameter('user', $userId)
            ->andWhere('IDENTITY(es.entry) IN (:ids)')->setParameter('ids', $entryIds)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['entryId'], $rows);
    }

    /**
     * Unread entry counts keyed by subscription id, in one query across all the
     * user's subscriptions. Unread = no explicit state and above the watermark,
     * OR an explicit isHidden=false row. Subscriptions with zero unread are
     * absent from the map (the caller defaults them to 0).
     *
     * Lives here, not on SubscriptionRepository, because its subject is read
     * state — it is rooted on Subscription with EntryState LEFT JOINed in,
     * the opposite shape from stateCountsForUser() above, so it cannot be
     * built with $this->createQueryBuilder() the way that method is.
     *
     * @return array<int, int>
     */
    public function unreadCountsForUser(int $userId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('s.id AS subscriptionId', 'COUNT(e.id) AS unreadCount')
            ->from(Subscription::class, 's')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
            ->andWhere('s.user = :user')
            ->andWhere(UnreadDql::predicate())
            ->groupBy('s.id')
            ->setParameter('user', $userId)
            ->setParameter('notHidden', false, Types::BOOLEAN);
        $this->collapse->apply(
            $qb,
            static function (QueryBuilder $inner, EntryAliases $aliases): void {
                $inner->andWhere(UnreadDql::predicate($aliases));
            },
            $userId,
        );

        /** @var list<array{subscriptionId: int, unreadCount: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['subscriptionId']] = (int) $row['unreadCount'];
        }

        return $map;
    }
}
