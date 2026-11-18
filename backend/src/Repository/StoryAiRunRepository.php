<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoryAiRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoryAiRun> */
final class StoryAiRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryAiRun::class);
    }

    /** @return list<int> */
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
