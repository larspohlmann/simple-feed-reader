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
 * caller's saved searches, as one paged stream. Split from EntryListRepository
 * — whose row projection and term-matching engine it reuses through
 * AbstractEntryProjectionRepository — because adding these two reads there
 * pushed that class over PHPMD's codesize thresholds.
 */
class SavedSearchEntryRepository extends AbstractEntryProjectionRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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

        return array_map(fn (array $row): EntryListRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The ids of every unread entry no newer than $until that matches any saved
     * search — the set the combined mark-read flips. Matched through the same
     * predicate the list uses, so it marks exactly what it shows.
     *
     * @return list<int>
     */
    public function unreadMatchIdsForSavedSearches(
        SavedSearchEntryQuery $query,
        \DateTimeImmutable $until,
    ): array {
        if ($query->termsPerSearch === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($query->userId);

        return $this->scalarIds(
            $qb->select('e.id')
                ->distinct()
                ->andWhere($this->anySearchMatches($qb, $query->termsPerSearch))
                ->andWhere('e.effectiveDate <= :until')
                ->setParameter('until', $until),
        );
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
            $predicates[] = $this->termsPredicate($qb, $terms, 'saved' . $position . 'term');
        }

        return '(' . implode(' OR ', $predicates) . ')';
    }
}
