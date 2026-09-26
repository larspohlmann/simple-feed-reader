<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearch;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
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

    public function getOneOwnedBy(int $id, int $userId): SavedSearch
    {
        return $this->findOneOwnedBy($id, $userId) ?? throw new RecordNotFoundException('No such saved search.');
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
     * furthest-behind first (#1116). Refreshed from the database: advanceMarks()
     * writes marks with DQL, which an already-loaded entity would not reflect.
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
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $searches;
    }

    /**
     * Moves the given searches' marks up to $entryId — never back: a slower
     * run that commits after a faster one must not undo its progress (#1116).
     *
     * @param non-empty-list<int> $savedSearchIds
     */
    public function advanceMarks(array $savedSearchIds, int $entryId): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . SavedSearch::class . ' s SET s.matchedUpToEntryId = :entryId'
            . ' WHERE s.id IN (:ids) AND s.matchedUpToEntryId < :entryId',
        )
            ->setParameter('entryId', $entryId)
            ->setParameter('ids', $savedSearchIds)
            ->execute();
    }

    /** Every search starts over at mark 0; the sweep re-matches the whole history. Answers how many. */
    public function resetAllMarks(): int
    {
        $reset = $this->getEntityManager()
            ->createQuery('UPDATE ' . SavedSearch::class . ' s SET s.matchedUpToEntryId = 0')
            ->execute();
        if (!\is_int($reset)) {
            throw new \LogicException('A DQL UPDATE answers the affected row count.');
        }

        return $reset;
    }
}
