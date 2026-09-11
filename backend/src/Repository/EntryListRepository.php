<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The reader's per-caller entry access: the "entry list row" projection
 * (entry + caller's subscription, feed, folded per-entry state) shared by
 * the entry list, search and "hydrate these ids" endpoints, plus the plain
 * per-entry subscription gate the reader-extraction endpoint uses.
 *
 * Split out of EntryRepository so that class's existence/lookup/keyset-walk
 * surface (ingestion, dedup, search reindex/backup batch walks) stays
 * readable — EntryController and the search services depend on this one
 * instead.
 *
 * Shares row hydration and term matching with SavedSearchEntryRepository.
 */
class EntryListRepository extends AbstractEntryProjectionRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly SearchTermsPredicateBuilder $termsPredicateBuilder,
        private readonly EntryScopePredicates $scope,
        private readonly DuplicateCollapseDql $collapse,
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
        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($query): void {
            $this->scope->applyList($qb, $aliases, $query);
        };

        $qb = $this->orderedBy($this->rowQueryBuilder($query->userId), $sort)
            ->setMaxResults($query->limit);
        $applyScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyScope, $query->userId);
        $this->applyCursor($qb, $query->cursor, $sort);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $applyScope, $query->userId);
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
        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($query): void {
            $this->scope->applySearch($qb, $aliases, $query);
        };
        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit);
        $applyScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyScope, $query->userId);
        // Search ranks by publish instant like the default list, never by view
        // time, so its cursor predicate is the effectiveDate one.
        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $applyScope, $query->userId);
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
     * Unread entries matching this search, newer than $since — the digest's
     * "new since last send" window (#636); mirrors
     * unreadMatchingEntryIdsForUser's `<=`. Newest-first so a caller can
     * slice the most recent handful (the digest's per-section cap) without
     * hydrating the whole set. `effectiveDate` rides in the SELECT because
     * DISTINCT forbids ordering by an unprojected column.
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
     * other list uses. Ordered like the entry list, never in the id order
     * asked for — a search engine's own ordering owes nothing to it.
     *
     * The subscription join is the real access gate: an id for a feed the
     * caller does not subscribe to is dropped here, even one from a search
     * index whose filter was wrong or stale.
     *
     * $limit caps the hydration in SQL for a caller that unions several id sets
     * but only shows the newest $limit of them (IndexedSavedSearchEntries): the
     * rows already come back newest-first, so the tail past $limit need never be
     * fetched or hydrated. Null hydrates every given id, as the single-search
     * and digest callers need.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    public function rowsByIdsForUser(array $entryIds, int $userId, ?int $limit = null): array
    {
        if ($entryIds === []) {
            return [];
        }

        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($entryIds): void {
            $this->scope->applyIds($qb, $aliases, $entryIds);
        };
        $rowQuery = $this->newestFirst($this->rowQueryBuilder($userId));
        $applyScope($rowQuery, EntryAliases::primary());
        $this->collapse->apply($rowQuery, $applyScope, $userId);
        if ($limit !== null) {
            $rowQuery->setMaxResults($limit);
        }

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $rowQuery->getQuery()->getResult();
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $applyScope, $userId);
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
        $qb->andWhere($this->termsPredicateBuilder->build($qb, $query->terms, 'term'));

        return $qb;
    }

    /**
     * Attach to each survivor the in-scope copies the collapse hid, so a card can
     * name them. One extra query per page over the same scope, minus the collapse
     * and the cursor.
     *
     * @param list<EntryListRow>                          $survivors
     * @param callable(QueryBuilder, EntryAliases): void  $applyScope
     *
     * @return list<EntryListRow>
     */
    private function attachDuplicates(array $survivors, callable $applyScope, int $userId): array
    {
        $hashes = [];
        $survivorIds = [];
        foreach ($survivors as $row) {
            $hash = $row->entry->getUrlHash();
            if ($hash !== null) {
                $hashes[$hash] = true;
                $survivorIds[] = (int) $row->entry->getId();
            }
        }
        if ($hashes === []) {
            return $survivors;
        }

        $qb = $this->rowQueryBuilder($userId);
        $applyScope($qb, EntryAliases::primary());
        $qb->andWhere('e.urlHash IN (:dupHashes)')
            ->andWhere('e.id NOT IN (:survivorIds)')
            ->setParameter('dupHashes', array_keys($hashes))
            ->setParameter('survivorIds', $survivorIds);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();
        $byHash = [];
        foreach ($rows as $raw) {
            $sibling = $this->rowHydrator->hydrate($raw);
            $byHash[(string) $sibling->entry->getUrlHash()][] = $sibling;
        }

        return array_map(
            static function (EntryListRow $row) use ($byHash): EntryListRow {
                $hash = $row->entry->getUrlHash();

                return $hash !== null && isset($byHash[$hash])
                    ? $row->withDuplicates($byHash[$hash])
                    : $row;
            },
            $survivors,
        );
    }
}
