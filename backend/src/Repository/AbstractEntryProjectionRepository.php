<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Http\EntryCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/**
 * The Doctrine-bound query construction EntryListRepository and
 * SavedSearchEntryRepository share: the row-projection join set, ordering,
 * and keyset cursor.
 *
 * @extends ServiceEntityRepository<Entry>
 */
abstract class AbstractEntryProjectionRepository extends ServiceEntityRepository
{
    protected function newestFirst(QueryBuilder $qb): QueryBuilder
    {
        return $this->orderedBy($qb, EntryListOrdering::byPublishedDate());
    }

    /** The sort's instant column, then id for the ties a refresh run leaves, both in the ordering's direction. */
    protected function orderedBy(QueryBuilder $qb, EntryListOrdering $ordering): QueryBuilder
    {
        $direction = $ordering->order->sqlDirection();

        return $qb
            ->orderBy($ordering->sort->orderColumn(), $direction)
            ->addOrderBy('e.id', $direction);
    }

    /**
     * The caller's unread entries, left for the reader to narrow and project.
     * Deliberately not rowQueryBuilder: every caller reduces to a scalar, and
     * that builder joins `feed` to select a title and a url nobody reads here.
     */
    protected function unreadEntriesQueryBuilder(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->setParameter('user', $userId)
            ->andWhere(UnreadDql::predicate())
            ->setParameter('notHidden', false, Types::BOOLEAN);
    }

    /**
     * The shared "entry list row" projection: the entry plus the caller's
     * subscription, feed, and optional per-entry state. listForUser adds
     * ordering/paging/filters; oneRowForUser adds an id filter.
     */
    protected function rowQueryBuilder(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.feed', 'f')->addSelect('f')
            // Unrelated-entity joins: the caller's subscription to this entry's
            // feed, and the caller's optional per-entry state row.
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isHidden AS esHidden')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('es.viewedAt AS esViewedAt')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->setParameter('user', $userId);
    }

    protected function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor, EntryListOrdering $ordering): void
    {
        if ($cursor === null) {
            return;
        }

        $qb->andWhere(\sprintf(
            '(%1$s %2$s :curInstant OR (%1$s = :curInstant AND e.id %2$s :curId))',
            $ordering->sort->orderColumn(),
            $ordering->order->strictlyAfter(),
        ))
            ->setParameter('curInstant', $cursor->sortInstant, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
    }

    /**
     * The distinct entry ids a match query selects, as a plain int list. The
     * shared tail of the unreadMember* readers: they differ only in their filter
     * and ordering, never in reducing `e.id` rows to ints.
     *
     * @return list<int>
     */
    protected function scalarIds(QueryBuilder $queryBuilder): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $queryBuilder->getQuery()->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
