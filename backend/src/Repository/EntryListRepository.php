<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Http\EntryCursor;
use App\Service\Search\LikePattern;
use App\Service\Search\SearchTerms;
use App\Service\Search\WordBoundaries;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
 * @extends ServiceEntityRepository<Entry>
 */
class EntryListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Entries in feeds the caller subscribes to, sorted newest first and
     * keyset-paginated on (sortInstant, id) — the sort instant is the entry's
     * effectiveDate for every view but "viewed", which is a reading history and
     * orders by EntryState.viewedAt instead (see EntryListSort). LEFT JOINs the
     * caller's EntryState and folds Subscription.markedReadUntil into an
     * effective isRead. `view` narrows to unread/favorites/kept/viewed.
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

        $this->applyView($qb, $query->view);
        $this->applyCursor($qb, $query->cursor, $sort);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
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

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * How many unread entries match this saved search. Reuses searchForUser's
     * term matching so the badge tracks the LIKE result set, plus the shared
     * "unread" predicate. Deliberately engine-independent: read state is
     * per-user and lives only in the database, never in the search index.
     */
    public function countUnreadMatchesForUser(EntrySearchQuery $query): int
    {
        $qb = $this->rowQueryBuilder($query->userId)
            ->select('COUNT(DISTINCT e.id)');
        $this->applyTerms($qb, $query->terms);
        $qb->andWhere(UnreadDql::predicate())->setParameter('readFalse', false, Types::BOOLEAN);

        return (int) $qb->getQuery()->getSingleScalarResult();
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
        $qb = $this->rowQueryBuilder($query->userId)
            ->select('e.id')
            ->distinct();
        $this->applyTerms($qb, $query->terms);
        $qb->andWhere(UnreadDql::predicate())->setParameter('readFalse', false, Types::BOOLEAN);
        $qb->andWhere('e.effectiveDate <= :until')->setParameter('until', $until);

        /** @var list<array{id: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
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

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
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

        return $row === null ? null : $this->hydrateRow($row);
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

    /**
     * The publish-date order every list but the "viewed" history shares. Kept
     * as its own name because search, the by-ids hydrator and the single-row
     * lookup never reorder — only listForUser does, through orderedBy().
     */
    private function newestFirst(QueryBuilder $qb): QueryBuilder
    {
        return $this->orderedBy($qb, EntryListSort::PublishedDate);
    }

    /**
     * The sort's instant column DESC, then id DESC as the tiebreaker a whole
     * refresh run's worth of tied instants needs. This is the tiebreak the
     * keyset cursor in applyCursor() depends on — the two read the same
     * EntryListSort, so the ORDER BY and the cursor predicate cannot name
     * different columns and desync a caller's pagination from its own cursor.
     */
    private function orderedBy(QueryBuilder $qb, EntryListSort $sort): QueryBuilder
    {
        return $qb
            ->orderBy($sort->orderColumn(), 'DESC')
            ->addOrderBy('e.id', 'DESC');
    }

    /**
     * The shared "entry list row" projection: the entry plus the caller's
     * subscription, feed, and optional per-entry state. listForUser adds
     * ordering/paging/filters; oneRowForUser adds an id filter.
     */
    private function rowQueryBuilder(int $userId): QueryBuilder
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
            ->addSelect('es.isRead AS esRead')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('es.viewedAt AS esViewedAt')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->setParameter('user', $userId);
    }

    private function applyView(QueryBuilder $qb, string $view): void
    {
        switch ($view) {
            case 'unread':
                $qb->andWhere(UnreadDql::predicate())->setParameter('readFalse', false, Types::BOOLEAN);
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
        foreach ($terms->terms as $position => $term) {
            $parameter = 'term' . $position;

            if ($terms->isWholeWord) {
                $this->applyWholeWordTerm($qb, $parameter, $term);
                continue;
            }

            $this->applySubstringTerm($qb, $parameter, $term);
        }
    }

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function applySubstringTerm(QueryBuilder $qb, string $parameter, string $term): void
    {
        $qb->andWhere(\sprintf(
            "(e.title LIKE :%s ESCAPE '%s' OR e.summary LIKE :%s ESCAPE '%s')",
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        ))->setParameter($parameter, LikePattern::containing($term));
    }

    /**
     * The plain "LIKE %term%" is ANDed in front of the normalized whole-word
     * check on purpose: it rejects almost every row with a cheap scan before
     * the expensive REPLACE chain runs, and costs nothing extra on the rows
     * where it does match.
     *
     * It is sound only while the raw term is a substring of every row the
     * normalized check would accept — true for a term of letters and digits,
     * FALSE as soon as the term carries boundary punctuation, because the two
     * sides then differ in exactly that punctuation. "E-Mail" and "E–Mail"
     * (en dash) normalize alike and must both match, yet neither is a raw
     * substring of the other. Such a term skips the prefilter and pays for the
     * chain; it is the rare shape, and a wrong answer is not worth the scan.
     */
    private function applyWholeWordTerm(QueryBuilder $qb, string $parameter, string $term): void
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->andWhere(\sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word),
            $this->wholeWordColumnPredicate('summary', $cheap, $word),
        ))->setParameter($word, LikePattern::wholeWord($term));

        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }
    }

    /**
     * One column's half of applyWholeWordTerm: the cheap "%term%" scan first
     * when it is sound, the normalized boundary check for the rows that
     * survive it.
     */
    private function wholeWordColumnPredicate(string $column, ?string $cheap, string $word): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;
        $normalized = \sprintf(
            "CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(e.%s), ' ') LIKE :%s ESCAPE '%s'",
            $column,
            $word,
            $escape,
        );

        if ($cheap === null) {
            return '(' . $normalized . ')';
        }

        return \sprintf(
            "(e.%s LIKE :%s ESCAPE '%s' AND %s)",
            $column,
            $cheap,
            $escape,
            $normalized,
        );
    }

    private function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor, EntryListSort $sort): void
    {
        if ($cursor === null) {
            return;
        }

        // Keyset "before" predicate for (sortInstant, id) DESC: strictly
        // earlier instants, or the same instant with a strictly smaller id.
        // The instant column is the one the ORDER BY uses, taken from the same
        // EntryListSort, so the two can never disagree.
        $column = $sort->orderColumn();
        $qb->andWhere(
            \sprintf('(%1$s < :curInstant OR (%1$s = :curInstant AND e.id < :curId))', $column),
        )
            ->setParameter('curInstant', $cursor->sortInstant, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
    }

    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    private function hydrateRow(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        return new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isRead: $this->rowIsRead($row, $entry),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            isViewed: (bool) ($row['esViewed'] ?? false),
            viewedAt: $row['esViewedAt'] instanceof \DateTimeImmutable
                ? $row['esViewedAt']
                : null,
            markedReadUntil: $row['markedReadUntil'] instanceof \DateTimeImmutable
                ? $row['markedReadUntil']
                : null,
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowIsRead(array $row, Entry $entry): bool
    {
        $esRead = $row['esRead'];
        $markedReadUntil = $row['markedReadUntil'];

        return EffectiveReadState::isRead(
            $esRead === null ? null : (bool) $esRead,
            $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
            $entry->getEffectiveDate(),
        );
    }

    /**
     * The subscription's display title: its custom override, else the feed
     * title, else the bare feed URL as a last resort.
     *
     * @param array<array-key, mixed> $row
     */
    private function rowTitle(array $row): string
    {
        $customTitle = $row['customTitle'];
        $feedTitle = $row['feedTitle'];
        $feedUrl = $row['feedUrl'];

        return SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            \is_string($feedTitle) ? $feedTitle : null,
            \is_string($feedUrl) ? $feedUrl : '',
        );
    }
}
