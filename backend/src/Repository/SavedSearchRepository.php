<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * @extends ServiceEntityRepository<SavedSearch>
 */
class SavedSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearch::class);
    }

    /**
     * @return list<SavedSearch> the user's saved searches, newest saved first
     */
    #[WithSpan]
    public function findForUser(int $userId): array
    {
        /** @var list<SavedSearch> $rows */
        $rows = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->orderBy('savedSearch.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<int> the user's saved-search ids, newest saved first
     */
    public function idsForUser(int $userId): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('savedSearch')
            ->select('savedSearch.id AS id')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->orderBy('savedSearch.id', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function findOneOwnedBy(int $id, int $userId): ?SavedSearch
    {
        /** @var SavedSearch|null $row */
        $row = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.id = :id')->setParameter('id', $id)
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * The user's saved searches flagged for the email digest, in list order.
     *
     * @return list<SavedSearch>
     */
    public function findIncludedInDigestForUser(int $userId): array
    {
        /** @var list<SavedSearch> $rows */
        $rows = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->andWhere('savedSearch.includeInDigest = true')
            ->orderBy('savedSearch.position', 'ASC')->addOrderBy('savedSearch.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneForUserByTerm(int $userId, string $term, bool $wholeWord, bool $phrase): ?SavedSearch
    {
        /** @var SavedSearch|null $row */
        $row = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->andWhere('savedSearch.term = :term')->setParameter('term', $term)
            ->andWhere('savedSearch.wholeWord = :wholeWord')->setParameter('wholeWord', $wholeWord)
            ->andWhere('savedSearch.phrase = :phrase')->setParameter('phrase', $phrase)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * Every search that has not yet checked every entry up to $ceiling, the
     * furthest-behind first so a new search's backfill is served before
     * steady-state work (#1116).
     *
     * @return list<SavedSearch>
     */
    public function findBelowMark(int $ceiling): array
    {
        /** @var list<SavedSearch> $searches */
        $searches = $this->createQueryBuilder('s')
            ->andWhere('s.matchedUpToEntryId < :ceiling')
            ->setParameter('ceiling', $ceiling)
            ->orderBy('s.matchedUpToEntryId', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $searches;
    }
}
