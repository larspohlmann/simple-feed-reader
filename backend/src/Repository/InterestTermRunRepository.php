<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InterestTermRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterestTermRun> */
final class InterestTermRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestTermRun::class);
    }

    public function findLatestForUser(User $user): ?InterestTermRun
    {
        /** @var InterestTermRun|null $run */
        $run = $this->createQueryBuilder('run')
            ->where('run.user = :user')
            ->setParameter('user', $user)
            ->orderBy('run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    /** @return list<int> newest first */
    public function findNewestIdsForUser(User $user, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('run')
            ->select('run.id AS id')
            ->where('run.user = :user')
            ->setParameter('user', $user)
            ->orderBy('run.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }
}
