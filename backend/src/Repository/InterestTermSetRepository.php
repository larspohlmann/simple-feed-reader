<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InterestTermSet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterestTermSet> */
final class InterestTermSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestTermSet::class);
    }

    public function findForUser(User $user): ?InterestTermSet
    {
        return $this->find($user);
    }

    public function hasForUser(int $userId): bool
    {
        return $this->createQueryBuilder('s')
            ->select('1')
            ->andWhere('IDENTITY(s.user) = :userId')
            ->setParameter('userId', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /** @return list<InterestTermSet> of active accounts whose mark is below the ceiling */
    public function findBelowMark(int $ceiling, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.user', 'owner')
            ->andWhere('s.matchedUpToEntryId < :ceiling')
            ->setParameter('ceiling', $ceiling);
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<InterestTermSet> $sets */
        $sets = $qb->getQuery()->getResult();

        return $sets;
    }

    /** @param list<int> $userIds */
    public function advanceMarks(array $userIds, int $entryId): void
    {
        if ($userIds === []) {
            return;
        }

        $this->createQueryBuilder('s')
            ->update()
            ->set('s.matchedUpToEntryId', ':entryId')
            ->andWhere('IDENTITY(s.user) IN (:userIds)')
            ->setParameter('entryId', $entryId)
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->execute();
    }

    /** @return list<InterestTermSet> of active accounts, whose rarities were never computed or are older than the cutoff */
    public function findWithStaleRarities(\DateTimeImmutable $refreshedBefore, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.user', 'owner')
            ->andWhere('s.raritiesRefreshedAt IS NULL OR s.raritiesRefreshedAt < :refreshedBefore')
            ->setParameter('refreshedBefore', $refreshedBefore);
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<InterestTermSet> $sets */
        $sets = $qb->getQuery()->getResult();

        return $sets;
    }
}
