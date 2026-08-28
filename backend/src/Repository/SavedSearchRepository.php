<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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

    public function findOneForUserByTerm(int $userId, string $term, bool $wholeWord): ?SavedSearch
    {
        /** @var SavedSearch|null $row */
        $row = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->andWhere('savedSearch.term = :term')->setParameter('term', $term)
            ->andWhere('savedSearch.wholeWord = :wholeWord')->setParameter('wholeWord', $wholeWord)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
