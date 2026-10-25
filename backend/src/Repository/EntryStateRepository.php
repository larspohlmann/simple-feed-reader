<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Service\Backup\RestoreEntryStates\RestoreEntryStatesInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryState>
 *
 * @phpstan-type StateCounts array{favorites: int, kept: int, viewed: int}
 */
final class EntryStateRepository extends ServiceEntityRepository implements RestoreEntryStatesInterface
{
    public function __construct(ManagerRegistry $registry)
    {
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
     * Idempotent insert of the (user, entry) state row, read since $hiddenSince or unread when it is null:
     * one racing writer wins, the other's INSERT is ignored, and an existing row keeps its flags.
     */
    public function ensureRow(int $userId, int $entryId, ?\DateTimeImmutable $hiddenSince): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $isMysql = DatabasePlatform::isMySql($connection);
        $conflictClause = $isMysql ? 'IGNORE' : 'OR IGNORE';

        $connection->executeStatement(
            sprintf(
                'INSERT %s INTO entry_state'
                . ' (user_id, entry_id, is_hidden, hidden_at, is_favorite, is_kept, is_viewed, viewed_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                $conflictClause,
            ),
            [$userId, $entryId, null !== $hiddenSince, $hiddenSince, false, false, false, null],
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
     * One page of entries' states keyed by entry id, for the backup's per-batch join. Entry is fetch-joined because
     * the state line's guidHash comes from it.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, EntryState>
     */
    public function forUserByEntryIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<EntryState> $states */
        $states = $this->createQueryBuilder('s')
            ->addSelect('e')
            ->join('s.entry', 'e')
            ->andWhere('s.user = :userId')
            ->andWhere('s.entry IN (:entryIds)')
            ->setParameter('userId', $userId)
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getResult();

        $statesByEntryId = [];
        foreach ($states as $state) {
            $entryId = $state->getEntry()->getId();
            if (null !== $entryId) {
                $statesByEntryId[$entryId] = $state;
            }
        }

        return $statesByEntryId;
    }

    /**
     * Favourite, kept and viewed totals, gated to still-subscribed feeds like the lists themselves, so a badge never
     * counts a state an unsubscribe orphaned.
     *
     * @return StateCounts
     */
    public function stateCountsForUser(int $userId): array
    {
        return $this->stateCounts($this->stateCountsQuery($userId));
    }

    /** @return StateCounts */
    public function plainFeedStateCountsForUser(int $userId): array
    {
        return $this->stateCounts(
            $this->stateCountsQuery($userId)->join('e.feed', 'f')->andWhere('f.network IS NULL'),
        );
    }

    /**
     * Read totals per still-subscribed plain feed, busiest first, at most $limit. Ids only: the client names each feed
     * from its own subscription list, so the custom title shows.
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
            ->andWhere('f.network IS NULL')
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

    private function stateCountsQuery(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('es')
            ->select('SUM(CASE WHEN es.isFavorite = :true THEN 1 ELSE 0 END) AS favorites')
            ->addSelect('SUM(CASE WHEN es.isKept = :true THEN 1 ELSE 0 END) AS kept')
            ->addSelect('SUM(CASE WHEN es.isViewed = :true THEN 1 ELSE 0 END) AS viewed')
            ->join('es.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN);
    }

    /** @return StateCounts */
    private function stateCounts(QueryBuilder $query): array
    {
        /** @var array{favorites: int|string|null, kept: int|string|null, viewed: int|string|null} $row */
        $row = $query->getQuery()->getSingleResult();

        return [
            'favorites' => (int) $row['favorites'],
            'kept' => (int) $row['kept'],
            'viewed' => (int) $row['viewed'],
        ];
    }
}
