<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use App\Service\Search\SearchTerms;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The reader's per-caller entry access: the "entry list row" projection
 * (entry plus the caller's subscription, feed, and folded per-entry state)
 * shared by the entry list, search and "hydrate these ids" endpoints, plus
 * the plain per-entry subscription gate the reader-extraction endpoint uses.
 * Split out of EntryRepository so that repository's existence/lookup/
 * keyset-walk surface (used by ingestion, dedup and the search reindex/
 * backup batch walks) does not grow past what a single class can stay
 * readable at — EntryController and the search services depend on this one
 * instead, EntryRepository's other methods never touch it.
 *
 * Shares row hydration and term matching with SavedSearchEntryRepository.
 */
class EntryListRepository extends AbstractEntryProjectionRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly SearchTermsPredicateBuilder $termsPredicateBuilder,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Entries in feeds the caller subscribes to, sorted newest first and
     * keyset-paginated on (sortInstant, id) — the sort instant is the entry's
     * effectiveDate for every view but "viewed", which is a reading history and
     * orders by EntryState.viewedAt instead (see EntryListSort). LEFT JOINs the
     * caller's EntryState and folds Subscription.markedReadUntil into an
     * effective isHidden. `view` narrows to unread/favorites/kept/viewed.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $sort = EntryListSort::forView($query->view);
        $qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $sort)
            ->setMaxResults($query->limit);

        if ($query->subscriptionId !== null) {
            $qb->andWhere('s.id = :sid')->setParameter('sid', $query->subscriptionId);
        }

        if ($query->tagId !== null) {
            // A tag matches at most one join row per subscription, so this inner
            // join never duplicates an entry. IDENTITY() reads the tag_id FK
            // without a second join to the tag table.
            $qb->innerJoin('s.subscriptionTags', 'st', 'WITH', 'IDENTITY(st.tag) = :tagId')
                ->setParameter('tagId', $query->tagId);
        }

        if ($query->hidesExcludedFeeds()) {
            $qb->andWhere('s.includeInAllItems = true');
        }

        $this->applyView($qb, $query->view);
        $this->applyCursor($qb, $query->cursor, $sort);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * Entries whose title or summary contains EVERY search term, newest first,
     * keyset-paginated exactly like the entry list. The predicate is an AND of
     * unindexable LIKEs, so the database reads every entry the caller
     * subscribes to; that cost is accepted for now and measured in #408.
     *
     * @return list<EntryListRow>
     */
    public function searchForUser(EntrySearchQuery $query): array
    {
        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit);

        $this->applyTerms($qb, $query->terms);
        // Search ranks by publish instant like the default list, never by view
        // time, so its cursor predicate is the effectiveDate one.
        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * The ids of every unread entry that matches this saved search, across all
     * the user's subscribed feeds. Reuses searchForUser's term matching so the
     * badge tracks the LIKE result set, plus the shared "unread" predicate.
     * Returns the ids rather than a bare count so the client can drop one the
     * moment the user reads it, without a fresh scan. Deliberately
     * engine-independent: read state is per-user and lives only in the
     * database, never in the search index.
     *
     * @return list<int>
     */
    public function unreadMatchIdsForUser(EntrySearchQuery $query): array
    {
        return $this->scalarIds($this->unreadMatchQueryBuilder($query)->select('e.id')->distinct());
    }

    /**
     * The ids of every unread entry that matches this search and is no newer
     * than $until, for the user's subscribed feeds. The set a search-scoped
     * mark-read must flip; reuses the search's own term matching so it marks
     * exactly what the search lists.
     *
     * @return list<int>
     */
    public function unreadMatchingEntryIdsForUser(EntrySearchQuery $query, \DateTimeImmutable $until): array
    {
        return $this->scalarIds(
            $this->unreadMatchQueryBuilder($query)
                ->select('e.id')
                ->distinct()
                ->andWhere('e.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
    }

    /**
     * The ids of every unread entry that matches this search and is NEWER than
     * $since, for the user's subscribed feeds — the digest's "new since the last
     * send" window (#636). The mirror of unreadMatchingEntryIdsForUser's `<=`.
     *
     * Ordered newest-first so a caller that only wants the most recent handful
     * (the digest caps each section) can slice the head without hydrating the
     * whole set. `effectiveDate` rides in the SELECT because DISTINCT forbids
     * ordering by a column it does not project.
     *
     * @return list<int>
     */
    public function unreadMatchIdsSince(EntrySearchQuery $query, \DateTimeImmutable $since): array
    {
        return $this->scalarIds(
            $this->unreadMatchQueryBuilder($query)
                ->select('e.id', 'e.effectiveDate')
                ->distinct()
                ->andWhere('e.effectiveDate > :since')
                ->setParameter('since', $since)
                ->orderBy('e.effectiveDate', 'DESC')
                ->addOrderBy('e.id', 'DESC'),
        );
    }

    /**
     * The given entry ids hydrated through the same list-row projection every
     * other list uses — same per-user read state, same subscription join.
     * Ordered exactly like the entry list, never in the id order asked for,
     * because a search engine's own ordering owes nothing to it.
     *
     * The subscription join is the actual access gate: an id for a feed the
     * caller does not subscribe to is dropped here even if it came from a
     * search index whose own filter was wrong or whose data was stale.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    public function rowsByIdsForUser(array $entryIds, int $userId): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $this->newestFirst(
            $this->rowQueryBuilder($userId)
                ->andWhere('e.id IN (:ids)')
                ->setParameter('ids', $entryIds),
        )
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * One entry as a list row (entry + subscription + folded state), or null if
     * the caller does not subscribe to its feed — the same IDOR gate as the list.
     * Lets a deep link open an entry the current list page does not contain.
     */
    public function oneRowForUser(int $entryId, int $userId): ?EntryListRow
    {
        /** @var array<array-key, mixed>|null $row */
        $row = $this->rowQueryBuilder($userId)
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : $this->rowHydrator->hydrate($row);
    }

    /**
     * The entry only if the caller subscribes to its feed — the IDOR gate for
     * per-entry state writes. Returns a managed Entry (or null → 404).
     */
    public function findOneSubscribedByUser(int $entryId, int $userId): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry;
    }

    private function unreadMatchQueryBuilder(EntrySearchQuery $query): QueryBuilder
    {
        $qb = $this->unreadEntriesQueryBuilder($query->userId);
        $this->applyTerms($qb, $query->terms);

        return $qb;
    }

    private function applyView(QueryBuilder $qb, string $view): void
    {
        switch ($view) {
            case 'unread':
                $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
                break;
            case 'favorites':
                $qb->andWhere('es.isFavorite = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'kept':
                $qb->andWhere('es.isKept = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            case 'viewed':
                $qb->andWhere('es.isViewed = :flag')->setParameter('flag', true, Types::BOOLEAN);
                break;
            default:
                // 'all' — no state filter.
                break;
        }
    }

    /**
     * The mode is decided once for the whole query (SearchTerms::$isWholeWord),
     * not per term — every term takes the same path.
     */
    private function applyTerms(QueryBuilder $qb, SearchTerms $terms): void
    {
        $qb->andWhere($this->termsPredicateBuilder->build($qb, $terms, 'term'));
    }
}
