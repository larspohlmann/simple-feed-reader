<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Service\Search\SavedSearchTerm;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The combined saved-search list (#769): every entry matching ANY of the
 * caller's saved searches, as one paged stream.
 */
final class SavedSearchEntryRepository extends AbstractEntryProjectionRepository
{
    /**
     * Searches per badge scan. Each search binds up to four parameters per
     * term twice (the WHERE and its CASE), so this keeps one statement well
     * under the smallest placeholder limit in play, SQLite's historical 999.
     */
    public const int SEARCHES_PER_SCAN = 25;

    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly SearchTermsPredicateBuilder $termsPredicateBuilder,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Every entry matching ANY of the caller's saved searches, newest first and
     * keyset-paginated like the entry list. No join here multiplies a row, so an
     * entry matching several searches is returned once without a DISTINCT.
     *
     * @return list<EntryListRow>
     */
    public function listForSavedSearches(SavedSearchEntryQuery $query): array
    {
        // An empty predicate list would OR to nothing and match every entry.
        if ($query->savedSearches === []) {
            return [];
        }

        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit);
        $qb->andWhere($this->anySearchMatches($qb, $query->savedSearches));

        if ($query->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * The ids of every unread entry no newer than $until that matches any saved
     * search — the set the combined mark-read flips. Matched through the same
     * predicate the list uses, so it marks exactly what it shows.
     *
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return list<int>
     */
    public function unreadMatchIdsForSavedSearches(
        int $userId,
        array $savedSearches,
        \DateTimeImmutable $until,
    ): array {
        if ($savedSearches === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($userId);

        return $this->scalarIds(
            $qb->select('e.id')
                ->distinct()
                ->andWhere($this->anySearchMatches($qb, $savedSearches))
                ->andWhere('e.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
    }

    /**
     * Saved-search id => the ids of every unread entry that search matches, for
     * all searches in one scan per chunk (#584): the WHERE keeps the rows any
     * search matches, a CASE per search flags which of them. Both come from the
     * predicate the list runs on, so a badge tracks exactly what opening the
     * search lists. Deliberately engine-independent: read state is per-user and
     * lives only in the database, never in the search index.
     *
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return array<int, list<int>>
     */
    public function unreadMatchIdsBySavedSearch(int $userId, array $savedSearches): array
    {
        $idsBySearch = [];
        foreach (array_chunk($savedSearches, self::SEARCHES_PER_SCAN) as $chunk) {
            $idsBySearch += $this->unreadMatchIdsInOneScan($userId, $chunk);
        }

        return $idsBySearch;
    }

    /**
     * Entry id => the id of the first saved search matching it, in the order
     * given — the sidebar's, so the row names the search the reader would look
     * for first. Takes the searches alone, not a SavedSearchEntryQuery: this
     * read restricts by id, not by owner or unread state.
     *
     * @param list<int>             $entryIds
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return array<int, int>
     */
    public function matchedSavedSearchIds(array $entryIds, array $savedSearches): array
    {
        if ($entryIds === [] || $savedSearches === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        /** @var list<array{id: int, matchedId: int}> $rows */
        $rows = $qb
            ->select('e.id', $this->firstMatchExpression($qb, $savedSearches))
            ->getQuery()
            ->getScalarResult();

        $matched = [];
        foreach ($rows as $row) {
            if ((int) $row['matchedId'] === 0) {
                continue;
            }

            $matched[(int) $row['id']] = (int) $row['matchedId'];
        }

        return $matched;
    }

    /**
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return array<int, list<int>>
     */
    private function unreadMatchIdsInOneScan(int $userId, array $savedSearches): array
    {
        $qb = $this->unreadEntriesQueryBuilder($userId)->select('e.id')->orderBy('e.id');
        foreach ($savedSearches as $position => $savedSearch) {
            $qb->addSelect($this->matchFlagExpression($qb, $position, $savedSearch));
        }
        $qb->andWhere($this->anySearchMatches($qb, $savedSearches));

        $idsBySearch = array_fill_keys(
            array_map(static fn (SavedSearchTerm $savedSearch): int => $savedSearch->id, $savedSearches),
            [],
        );
        // Doctrine types the mapped id; a CASE is raw, and MySQL hands it back as a string.
        /** @var list<array{id: int, ...<string, int|string>}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            foreach ($savedSearches as $position => $savedSearch) {
                if ((int) $row['match' . $position] === 1) {
                    $idsBySearch[$savedSearch->id][] = $row['id'];
                }
            }
        }

        return $idsBySearch;
    }

    private function matchFlagExpression(QueryBuilder $qb, int $position, SavedSearchTerm $savedSearch): string
    {
        return \sprintf(
            'CASE WHEN %s THEN 1 ELSE 0 END AS match%d',
            $this->termsPredicateBuilder->build($qb, $savedSearch->terms, 'flag' . $position . 'term'),
            $position,
        );
    }

    /**
     * One predicate for "matches any of these searches" — each search's own
     * terms still ANDed inside it, the searches ORed between them.
     *
     * @param list<SavedSearchTerm> $savedSearches
     */
    private function anySearchMatches(QueryBuilder $qb, array $savedSearches): string
    {
        $predicates = [];
        foreach ($savedSearches as $position => $savedSearch) {
            $predicates[] = $this->termsPredicateBuilder->build(
                $qb,
                $savedSearch->terms,
                'saved' . $position . 'term',
            );
        }

        return '(' . implode(' OR ', $predicates) . ')';
    }

    /**
     * A CASE that answers the first matching search's id, so "first" is decided
     * by the same predicates the list itself matched on rather than by a second
     * implementation of the matching rules.
     *
     * @param list<SavedSearchTerm> $savedSearches
     */
    private function firstMatchExpression(QueryBuilder $qb, array $savedSearches): string
    {
        $branches = '';
        foreach ($savedSearches as $position => $savedSearch) {
            $branches .= \sprintf(
                ' WHEN %s THEN %d',
                $this->termsPredicateBuilder->build(
                    $qb,
                    $savedSearch->terms,
                    'match' . $position . 'term',
                ),
                $savedSearch->id,
            );
        }

        return 'CASE' . $branches . ' ELSE 0 END AS matchedId';
    }
}
