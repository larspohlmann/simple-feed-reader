<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\EntryPlanHint;
use App\Doctrine\EntryPlanHintWalker;
use App\Entity\DiscussionVote;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\SocialLike;
use App\Entity\Subscription;
use App\Enum\EntryView;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The caller's entry list rows (entry, subscription, feed, folded state) for the list, search and id-hydration reads,
 * plus the per-entry subscription gate. Ingestion lookups and batch walks stay on EntryRepository.
 *
 * @extends ServiceEntityRepository<Entry>
 */
final class EntryListRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly SearchTermsPredicateBuilder $termsPredicateBuilder,
        private readonly EntryScopePredicates $scope,
        private readonly DuplicateCollapseDql $collapse,
        private readonly DateOrderedPage $dateOrderedPage,
        private readonly EntryProjection $projection,
        private readonly SubscriptionScopeRepository $subscriptionScopes,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Entries in the caller's feeds, keyset-paged on (sort instant, id) as EntryListSort defines it, with the
     * subscription's watermark folded into isHidden. `view` narrows the list.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $groupSubscriptionIds = $this->feedGroupSubscriptionIds($query);
        if ($groupSubscriptionIds === []) {
            return [];
        }

        $ordering = $query->ordering();
        $applyScope = $this->buildListScope($query, $groupSubscriptionIds);
        $collapseScope = CollapseScope::fromScopes(
            $applyScope,
            $this->buildListScope($query->withoutReadFilter(), $groupSubscriptionIds),
        );

        $pageQuery = function () use ($query, $ordering, $applyScope, $collapseScope): QueryBuilder {
            $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($query->userId), $ordering)
                ->setMaxResults($query->limit);
            $applyScope($qb, EntryAliases::primary());
            $this->collapse->apply($qb, $collapseScope, $query->userId);
            $this->projection->applyCursor($qb, $query->cursor, $ordering);

            return $qb;
        };
        $windowProbe = function () use ($query): QueryBuilder {
            $probe = $this->createQueryBuilder('e')->select('e.dates.effectiveDate AS effectiveDate');
            $probeOrdering = EntryListOrdering::byPublishedDate($query->order);
            $qb = $this->projection->orderedBy($probe, $probeOrdering);
            $this->projection->applyCursor($qb, $query->cursor, $probeOrdering);

            return $qb;
        };

        $rows = $this->dateOrderedPage->rows(
            $query,
            new DateOrderedQueries(
                $pageQuery,
                $windowProbe,
                $this->scopeProbe($query, $groupSubscriptionIds),
            ),
        );
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $collapseScope, $query->userId);
    }

    /**
     * What the liked view lists, through the same scope and copy collapse so the badge matches the list. The poll asks
     * this every tick, so it starts from the reader's like rows, never from every subscribed entry.
     */
    public function countLikedForUser(int $userId): int
    {
        $likedEntryIds = $this->findLikedEntryIds($userId);
        if ($likedEntryIds === []) {
            return 0;
        }
        $applyScope = $this->buildListScope(new EntryQuery($userId, EntryView::Liked), null);
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user');
        $this->scope->applyIds($qb, EntryAliases::primary(), $likedEntryIds);
        $applyScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, CollapseScope::fromScope($applyScope), $userId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<int> the subscribed entries a local like, a network like or an up-vote of the reader reaches */
    private function findLikedEntryIds(int $userId): array
    {
        $likeRows = [
            $this->buildLikedEntryQuery(EntryState::class, 'liked.entry = e')
                ->andWhere(EntryLikeDql::buildLocalLikeCondition('liked')),
            $this->buildLikedEntryQuery(SocialLike::class, 'e.canonicalItem = liked.canonicalItem'),
            $this->buildLikedEntryQuery(DiscussionVote::class, 'e.canonicalItem = liked.canonicalItem')
                ->andWhere(EntryLikeDql::buildUpvoteCondition('liked')),
        ];
        $entryIds = array_merge(...array_map(
            fn (QueryBuilder $qb): array => $this->projection->scalarIds($qb->setParameter('user', $userId)),
            $likeRows,
        ));

        return array_values(array_unique($entryIds));
    }

    /** @param class-string $likeClass */
    private function buildLikedEntryQuery(string $likeClass, string $entryCondition): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('e.id')
            ->from($likeClass, 'liked')
            ->join(Entry::class, 'e', Join::ON, $entryCondition)
            ->join(Subscription::class, 's', Join::ON, 's.feed = e.feed AND s.user = :user')
            ->andWhere('liked.user = :user');
    }

    /**
     * @param non-empty-list<int>|null $groupSubscriptionIds
     *
     * @return \Closure(QueryBuilder, EntryAliases): void
     */
    private function buildListScope(EntryQuery $query, ?array $groupSubscriptionIds): \Closure
    {
        return function (QueryBuilder $qb, EntryAliases $aliases) use ($query, $groupSubscriptionIds): void {
            $this->scope->applyList($qb, $aliases, $query);
            if ($groupSubscriptionIds !== null) {
                $this->scope->inSubscriptions($qb, $aliases, $groupSubscriptionIds);
            }
        };
    }

    /**
     * @param non-empty-list<int>|null $groupSubscriptionIds
     *
     * @return \Closure(): QueryBuilder
     */
    private function scopeProbe(EntryQuery $query, ?array $groupSubscriptionIds): \Closure
    {
        return function () use ($query, $groupSubscriptionIds): QueryBuilder {
            $qb = $this->createQueryBuilder('e')
                ->select('e.id')
                ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
                ->setParameter('user', $query->userId);
            $this->buildListScope($query->inAllView(), $groupSubscriptionIds)($qb, EntryAliases::primary());

            return $qb;
        };
    }

    /**
     * Entries whose title, summary or transcript holds every term, paged like the entry list. On MySQL the LIKEs use no
     * index, so this reads every subscribed entry; that cost is accepted and measured in #408. SQLite asks its index.
     *
     * @return list<EntryListRow>
     */
    public function searchForUser(EntrySearchQuery $query): array
    {
        $applyScope = $this->buildSearchScope($query);
        $ordering = $query->ordering();
        $qb = $this->projection->orderedBy($this->projection->rowQueryBuilder($query->userId), $ordering)
            ->setMaxResults($query->limit);
        $applyScope($qb, EntryAliases::primary());
        $collapseScope = CollapseScope::fromScopes($applyScope, $this->buildSearchScope($query->withoutReadFilter()));
        $this->collapse->apply($qb, $collapseScope, $query->userId);
        $this->projection->applyCursor($qb, $query->cursor, $ordering);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $collapseScope, $query->userId);
    }

    /**
     * @return \Closure(QueryBuilder, EntryAliases): void
     */
    private function buildSearchScope(EntrySearchQuery $query): \Closure
    {
        return function (QueryBuilder $qb, EntryAliases $aliases) use ($query): void {
            $this->scope->applySearch($qb, $aliases, $query);
        };
    }

    /**
     * The unread entries, no newer than $until, that a search-scoped mark-read flips. It uses the search's own term
     * predicate, so it marks exactly what the search lists.
     *
     * @return list<int>
     */
    public function unreadMatchingEntryIdsForUser(EntrySearchQuery $query, \DateTimeImmutable $until): array
    {
        return $this->projection->scalarIds(
            $this->unreadMatchQueryBuilder($query)
                ->select('e.id')
                ->distinct()
                ->andWhere('e.dates.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
    }

    /**
     * The given ids as list rows, ordered like the entry list and never in the order asked for. The subscription
     * join is the access gate: an id from a feed the caller does not follow is dropped, even when a search index
     * returned it.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    public function rowsByIdsForUser(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $applyScope = function (QueryBuilder $qb, EntryAliases $aliases) use ($entryIds): void {
            $this->scope->applyIds($qb, $aliases, $entryIds);
        };
        $rowQuery = $this->projection->newestFirst($this->projection->rowQueryBuilder($userId));
        $applyScope($rowQuery, EntryAliases::primary());
        $collapseScope = CollapseScope::fromScope($applyScope);
        $this->collapse->apply($rowQuery, $collapseScope, $userId);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $rowQuery->getQuery()->getResult();
        $survivors = array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);

        return $this->attachDuplicates($survivors, $collapseScope, $userId);
    }

    /**
     * One entry as a list row (entry + subscription + folded state) — the same
     * IDOR gate as the list. Lets a deep link open an entry the current list
     * page does not contain.
     */
    public function getRowForUser(int $userId, int $entryId): EntryListRow
    {
        /** @var array<array-key, mixed>|null $row */
        $row = $this->projection->rowQueryBuilder($userId)
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw new RecordNotFoundException('No such entry.');
        }

        return $this->rowHydrator->hydrate($row);
    }

    /** The entry only if the caller subscribes to its feed — the IDOR gate for per-entry state writes. */
    public function getOneSubscribedForUser(int $userId, int $entryId): Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->createQueryBuilder('e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('e.id = :id')
            ->setParameter('id', $entryId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $entry ?? throw new RecordNotFoundException('No such entry.');
    }

    /**
     * The feed group as the caller's subscription ids, or null when the list is not a group. A constant id list lets
     * MySQL drive the page from `subscription`; the tag predicate itself forces a walk of every entry (#1735).
     *
     * @return list<int>|null
     */
    private function feedGroupSubscriptionIds(EntryQuery $query): ?array
    {
        if ($query->feedGroup === null) {
            return null;
        }

        return $this->subscriptionScopes->idsForUserInFeedGroup($query->userId, $query->feedGroup);
    }

    private function unreadMatchQueryBuilder(EntrySearchQuery $query): QueryBuilder
    {
        $qb = $this->projection->unreadEntriesQueryBuilder($query->userId);
        $qb->andWhere($this->termsPredicateBuilder->build($qb, $query->terms, 'term'));

        return $qb;
    }

    /**
     * Attach to each survivor the in-scope copies the collapse hid, so a card can
     * name them, then its cross-posts. One extra query per key and page over the
     * same scope, minus the collapse and the cursor.
     *
     * @param list<EntryListRow> $survivors
     *
     * @return list<EntryListRow>
     */
    private function attachDuplicates(array $survivors, CollapseScope $scope, int $userId): array
    {
        $hashes = self::collectKeys($survivors, static fn (Entry $entry): ?string => $entry->getUrlHash());
        $itemIds = self::collectKeys(
            $survivors,
            static fn (Entry $entry): ?int => $entry->getCanonicalItemId(),
        );
        $crossPostHashes = self::collectKeys(
            $survivors,
            static fn (Entry $entry): ?string => $entry->getCanonicalItem()?->getCrossPostHash(),
        );
        if ($hashes === [] && $itemIds === [] && $crossPostHashes === []) {
            return $survivors;
        }

        $survivorIds = array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $survivors);
        $buildCopiesQuery = function (\Closure $applyScope) use ($userId, $survivorIds): QueryBuilder {
            $qb = $this->projection->rowQueryBuilder($userId);
            $applyScope($qb, EntryAliases::primary());

            return $qb->andWhere('e.id NOT IN (:survivorIds)')->setParameter('survivorIds', $survivorIds);
        };
        $copiesByHash = $hashes === [] ? [] : $this->findCopiesByUrlHash($buildCopiesQuery($scope->copies), $hashes);
        $copiesByItem = $itemIds === [] ? [] : $this->findCopiesByItem($buildCopiesQuery($scope->copies), $itemIds);
        $crossPostsByHash = $crossPostHashes === []
            ? []
            : $this->findCrossPosts($buildCopiesQuery($scope->crossPosts), $crossPostHashes, $itemIds);

        return array_map(
            static fn (EntryListRow $row): EntryListRow => self::attachCrossPosts(
                self::attachCopies($row, $copiesByHash, $copiesByItem),
                $crossPostsByHash,
            ),
            $survivors,
        );
    }

    /**
     * @param list<string> $hashes
     *
     * @return array<string, list<EntryListRow>>
     */
    private function findCopiesByUrlHash(QueryBuilder $qb, array $hashes): array
    {
        $query = $qb->andWhere('e.location.urlHash IN (:dupHashes)')->setParameter('dupHashes', $hashes)->getQuery();
        EntryPlanHintWalker::apply($query, EntryPlanHint::DuplicateLookup);

        return $this->groupCopies($query, static fn (Entry $entry): ?string => $entry->getUrlHash());
    }

    /**
     * @param list<int> $itemIds
     *
     * @return array<int, list<EntryListRow>>
     */
    private function findCopiesByItem(QueryBuilder $qb, array $itemIds): array
    {
        $query = $qb->andWhere('e.canonicalItem IN (:dupItems)')->setParameter('dupItems', $itemIds)->getQuery();

        return $this->groupCopies($query, static fn (Entry $entry): ?int => $entry->getCanonicalItemId());
    }

    /**
     * @param list<string> $hashes
     * @param list<int>    $survivorItemIds
     *
     * @return array<string, list<EntryListRow>>
     */
    private function findCrossPosts(QueryBuilder $qb, array $hashes, array $survivorItemIds): array
    {
        $query = $qb->andWhere('ci.crossPostHash IN (:crossPostHashes)')
            ->andWhere('e.canonicalItem NOT IN (:survivorItemIds)')
            ->setParameter('crossPostHashes', $hashes)
            ->setParameter('survivorItemIds', $survivorItemIds)
            ->orderBy('e.dates.publishedAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery();

        return $this->groupCopies(
            $query,
            static fn (Entry $entry): ?string => $entry->getCanonicalItem()?->getCrossPostHash(),
        );
    }

    /**
     * @template TKey of array-key
     *
     * @param Query<null, mixed> $query
     * @param \Closure(Entry): ?TKey $keyOf
     *
     * @return array<TKey, list<EntryListRow>>
     */
    private function groupCopies(Query $query, \Closure $keyOf): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = $query->getResult();
        $copies = [];
        foreach ($this->hydrateRows($rows) as $copy) {
            $key = $keyOf($copy->entry);
            if ($key !== null) {
                $copies[$key][] = $copy;
            }
        }

        return $copies;
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     *
     * @return list<EntryListRow>
     */
    private function hydrateRows(array $rows): array
    {
        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * @param array<string, list<EntryListRow>> $copiesByHash
     * @param array<int, list<EntryListRow>>    $copiesByItem
     */
    private static function attachCopies(EntryListRow $row, array $copiesByHash, array $copiesByItem): EntryListRow
    {
        $copies = [];
        $hash = $row->entry->getUrlHash();
        $itemId = $row->entry->getCanonicalItemId();
        $found = [
            ...($hash === null ? [] : $copiesByHash[$hash] ?? []),
            ...($itemId === null ? [] : $copiesByItem[$itemId] ?? []),
        ];
        foreach ($found as $copy) {
            $copies[$copy->entry->requireId()] ??= $copy;
        }

        return $copies === [] ? $row : $row->withDuplicates(array_values($copies));
    }

    /**
     * @param array<string, list<EntryListRow>> $crossPostsByHash
     */
    private static function attachCrossPosts(EntryListRow $row, array $crossPostsByHash): EntryListRow
    {
        $hash = $row->entry->getCanonicalItem()?->getCrossPostHash();
        if ($hash === null || !isset($crossPostsByHash[$hash])) {
            return $row;
        }
        $pills = [];
        foreach ($crossPostsByHash[$hash] as $crossPost) {
            $itemId = $crossPost->entry->getCanonicalItemId();
            if ($itemId !== null) {
                $pills[$itemId] ??= $crossPost;
            }
        }

        return $row->withDuplicates([...$row->duplicates, ...array_values($pills)]);
    }

    /**
     * @template TKey of int|string
     *
     * @param list<EntryListRow>          $rows
     * @param callable(Entry): (TKey|null) $readKey
     *
     * @return list<TKey>
     */
    private static function collectKeys(array $rows, callable $readKey): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = $readKey($row->entry);
            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
