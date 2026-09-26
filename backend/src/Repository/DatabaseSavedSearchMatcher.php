<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The LIKE matcher: one statement per chunk answers every search at once — the
 * WHERE keeps the candidates any search matches, a CASE per search flags which.
 * Title and summary only, exact terms — the database host's recall.
 */
final readonly class DatabaseSavedSearchMatcher implements SavedSearchMatcher
{
    /**
     * A search binds up to 24 parameters (six terms, two each, in the WHERE and
     * its CASE); with the 500 candidate ids, 20 keep one statement under
     * SQLite's historical 999.
     */
    private const int SEARCHES_PER_STATEMENT = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private SearchTermsPredicateBuilder $predicates,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        if ($candidateEntryIds === []) {
            return array_fill_keys(SavedSearchTerm::idsOf($searches), []);
        }

        $matches = [];
        foreach (array_chunk($searches, self::SEARCHES_PER_STATEMENT) as $chunk) {
            $matches += $this->matchesInOneStatement($chunk, $candidateEntryIds);
        }

        return $matches;
    }

    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $candidateEntryIds
     *
     * @return array<int, list<int>>
     */
    private function matchesInOneStatement(array $searches, array $candidateEntryIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->andWhere('e.id IN (:candidates)')
            ->setParameter('candidates', $candidateEntryIds)
            ->orderBy('e.id', 'ASC');

        $anyMatches = [];
        foreach ($searches as $position => $search) {
            $qb->addSelect(\sprintf(
                'CASE WHEN %s THEN 1 ELSE 0 END AS match%d',
                $this->predicates->build($qb, $search->terms, 'flag' . $position . 'term'),
                $position,
            ));
            $anyMatches[] = $this->predicates->build($qb, $search->terms, 'any' . $position . 'term');
        }
        $qb->andWhere('(' . implode(' OR ', $anyMatches) . ')');

        return $this->collect($searches, $qb);
    }

    /**
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>>
     */
    private function collect(array $searches, QueryBuilder $qb): array
    {
        $matches = array_fill_keys(SavedSearchTerm::idsOf($searches), []);
        // Doctrine types the mapped id; a CASE is raw, and MySQL hands it back as a string.
        /** @var list<array{id: int, ...<string, int|string>}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            foreach ($searches as $position => $search) {
                if ((int) $row['match' . $position] === 1) {
                    $matches[$search->id][] = (int) $row['id'];
                }
            }
        }

        return $matches;
    }
}
