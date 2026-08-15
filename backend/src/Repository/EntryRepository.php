<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Http\EntryCursor;
use App\Service\Search\LikePattern;
use App\Service\Search\SearchTerms;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * @param list<string> $guidHashes
     *
     * @return list<string> the subset of hashes that already exist for this feed
     */
    public function findExistingGuidHashes(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.guidHash')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> the subset of ids that still exist — a caller holding
     *                   ids from an earlier checkpoint uses this to drop the
     *                   ones pruned since
     */
    public function findExistingIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * The feed's existing entries for the given guid hashes, indexed by hash —
     * lets a re-parse match items back to their persisted rows.
     *
     * @param list<string> $guidHashes
     *
     * @return array<string, Entry>
     */
    public function findByFeedIndexedByGuidHash(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<Entry> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getResult();

        $byHash = [];
        foreach ($rows as $entry) {
            $byHash[$entry->getGuidHash()] = $entry;
        }

        return $byHash;
    }

    /**
     * Entries in feeds the caller subscribes to, sorted by effectiveDate DESC
     * (the entry's list-sort instant — see EntryEffectiveDate), then id DESC
     * as the tiebreaker a whole refresh run's worth of tied dates needs.
     * Keyset-paginated on (effectiveDate, id). LEFT JOINs the caller's
     * EntryState and folds Subscription.markedReadUntil into an effective
     * isRead. `view` narrows to unread/favorites/kept.
     *
     * @return list<EntryListRow>
     */
    public function listForUser(EntryQuery $query): array
    {
        $qb = $this->rowQueryBuilder($query->userId)
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
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
        $this->applyCursor($qb, $query->cursor);

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
        $qb = $this->rowQueryBuilder($query->userId)
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($query->limit);

        $this->applyTerms($qb, $query->terms);
        $this->applyCursor($qb, $query->cursor);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
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
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->setParameter('user', $userId);
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
     */
    private function applyWholeWordTerm(QueryBuilder $qb, string $parameter, string $term): void
    {
        $cheap = $parameter . 'Cheap';
        $word = $parameter . 'Word';

        $qb->andWhere(\sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word),
            $this->wholeWordColumnPredicate('summary', $cheap, $word),
        ))
            ->setParameter($cheap, LikePattern::containing($term))
            ->setParameter($word, LikePattern::wholeWord($term));
    }

    /**
     * One column's half of applyWholeWordTerm: the cheap "%term%" scan first,
     * the normalized boundary check only for the rows that survive it.
     */
    private function wholeWordColumnPredicate(string $column, string $cheap, string $word): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;

        return \sprintf(
            "(e.%s LIKE :%s ESCAPE '%s' AND CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(e.%s), ' ') LIKE :%s ESCAPE '%s')",
            $column,
            $cheap,
            $escape,
            $column,
            $word,
            $escape,
        );
    }

    private function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        // Keyset "before" predicate for (effectiveDate, id) DESC: strictly
        // earlier dates, or the same date with a strictly smaller id.
        $qb->andWhere(
            '(e.effectiveDate < :curEffectiveDate '
            . 'OR (e.effectiveDate = :curEffectiveDate AND e.id < :curId))',
        )
            ->setParameter('curEffectiveDate', $cursor->effectiveDate, Types::DATETIME_IMMUTABLE)
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
