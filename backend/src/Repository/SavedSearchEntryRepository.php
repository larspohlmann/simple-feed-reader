<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Service\Search\SearchTerms;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The combined saved-search list (#769): every entry matching ANY of the
 * caller's saved searches, as one paged stream.
 */
final class SavedSearchEntryRepository extends AbstractEntryProjectionRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EntryListRowHydrator $rowHydrator,
        private readonly SearchTermsPredicateBuilder $termsPredicateBuilder,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Every entry matching ANY of the caller's saved searches, newest first and
     * keyset-paginated exactly like the entry list. One query, so the cursor
     * and the sort are the list's own; collecting ids per search and merging
     * them could not page. No join here multiplies a row, so an entry that
     * matches several searches is returned once without a DISTINCT.
     *
     * Deliberately no `includeInAllItems` filter: a search ignores that flag,
     * and this view is built from searches (#769).
     *
     * @return list<EntryListRow>
     */
    public function listForSavedSearches(SavedSearchEntryQuery $query): array
    {
        // An empty predicate list would OR to nothing and match every entry.
        if ($query->termsPerSearch === []) {
            return [];
        }

        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit);
        $qb->andWhere($this->anySearchMatches($qb, $query->termsPerSearch));

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
     * @param list<SearchTerms> $termsPerSearch
     *
     * @return list<int>
     */
    public function unreadMatchIdsForSavedSearches(
        int $userId,
        array $termsPerSearch,
        \DateTimeImmutable $until,
    ): array {
        if ($termsPerSearch === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($userId);

        return $this->scalarIds(
            $qb->select('e.id')
                ->distinct()
                ->andWhere($this->anySearchMatches($qb, $termsPerSearch))
                ->andWhere('e.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
    }

    /**
     * Entry id => the id of the first saved search that matches it, in the
     * order given. The order is the sidebar's, so the row names the search the
     * reader would look for first. Takes the terms alone, not a
     * SavedSearchEntryQuery: this read restricts by id, not by owner or
     * unread state, so its signature must not suggest otherwise.
     *
     * @param list<int>          $entryIds
     * @param list<SearchTerms>  $termsPerSearch
     * @param list<int>          $savedSearchIds
     *
     * @return array<int, int>
     */
    public function matchedSavedSearchIds(
        array $entryIds,
        array $termsPerSearch,
        array $savedSearchIds,
    ): array {
        if ($entryIds === [] || $termsPerSearch === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        /** @var list<array{id: int, matchedId: int}> $rows */
        $rows = $qb
            ->select('e.id', $this->firstMatchExpression($qb, $termsPerSearch, $savedSearchIds))
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
     * One predicate for "matches any of these searches" — each search's own
     * terms still ANDed inside it, the searches ORed between them.
     *
     * @param list<SearchTerms> $termsPerSearch
     */
    private function anySearchMatches(QueryBuilder $qb, array $termsPerSearch): string
    {
        $predicates = [];
        foreach ($termsPerSearch as $position => $terms) {
            $predicates[] = $this->termsPredicateBuilder->build($qb, $terms, 'saved' . $position . 'term');
        }

        return '(' . implode(' OR ', $predicates) . ')';
    }

    /**
     * A CASE that answers the first matching search's id, so "first" is decided
     * by the same predicates the list itself matched on rather than by a second
     * implementation of the matching rules.
     *
     * @param list<SearchTerms> $termsPerSearch
     * @param list<int>         $savedSearchIds
     */
    private function firstMatchExpression(
        QueryBuilder $qb,
        array $termsPerSearch,
        array $savedSearchIds,
    ): string {
        $branches = '';
        foreach ($termsPerSearch as $position => $terms) {
            $branches .= \sprintf(
                ' WHEN %s THEN %d',
                $this->termsPredicateBuilder->build($qb, $terms, 'match' . $position . 'term'),
                $savedSearchIds[$position],
            );
        }

        return 'CASE' . $branches . ' ELSE 0 END AS matchedId';
    }
}
