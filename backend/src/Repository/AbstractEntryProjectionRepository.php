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

/**
 * The "entry list row" projection and term-matching engine EntryListRepository
 * and SavedSearchEntryRepository both build on: the join set, the keyset
 * cursor, and the LIKE predicate a search's terms compile to. Split out so
 * that a second reader over the same rows — the combined saved-search list
 * (#769) — does not push EntryListRepository over PHPMD's codesize
 * thresholds merely by adding two methods that reuse everything already here.
 *
 * @extends ServiceEntityRepository<Entry>
 */
abstract class AbstractEntryProjectionRepository extends ServiceEntityRepository
{
    /**
     * The publish-date order every list but the "viewed" history shares. Kept
     * as its own name because search, the by-ids hydrator and the single-row
     * lookup never reorder — only listForUser does, through orderedBy().
     */
    protected function newestFirst(QueryBuilder $qb): QueryBuilder
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
    protected function orderedBy(QueryBuilder $qb, EntryListSort $sort): QueryBuilder
    {
        return $qb
            ->orderBy($sort->orderColumn(), 'DESC')
            ->addOrderBy('e.id', 'DESC');
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

    /**
     * One search's terms as a single ANDed expression the caller places itself.
     * The combined saved-search read ORs several of these, which andWhere()
     * cannot express. $prefix keys the bound parameters, so two searches that
     * share a word cannot overwrite each other's value.
     */
    protected function termsPredicate(QueryBuilder $qb, SearchTerms $terms, string $prefix): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $predicates[] = $terms->isWholeWord
                ? $this->wholeWordPredicate($qb, $parameter, $term)
                : $this->substringPredicate($qb, $parameter, $term);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    protected function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor, EntryListSort $sort): void
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
     * The distinct entry ids a match query selects, as a plain int list. The
     * shared tail of the unreadMatch* readers: they differ only in their filter
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

    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => Entry, scalars...]
     */
    protected function hydrateRow(array $row): EntryListRow
    {
        /** @var Entry $entry */
        $entry = $row[0];

        return new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isHidden: $this->rowIsHidden($row, $entry),
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

    /**
     * A summary is nullable, and NULL LIKE … is never true, so the OR alone
     * handles an entry that carries no summary.
     */
    private function substringPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return \sprintf(
            "(e.title LIKE :%s ESCAPE '%s' OR e.summary LIKE :%s ESCAPE '%s')",
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
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
    private function wholeWordPredicate(QueryBuilder $qb, string $parameter, string $term): string
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return \sprintf(
            '(%s OR %s)',
            $this->wholeWordColumnPredicate('title', $cheap, $word),
            $this->wholeWordColumnPredicate('summary', $cheap, $word),
        );
    }

    /**
     * One column's half of wholeWordPredicate: the cheap "%term%" scan first
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

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowIsHidden(array $row, Entry $entry): bool
    {
        $esHidden = $row['esHidden'];
        $markedReadUntil = $row['markedReadUntil'];

        return EffectiveReadState::isHidden(
            $esHidden === null ? null : (bool) $esHidden,
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
