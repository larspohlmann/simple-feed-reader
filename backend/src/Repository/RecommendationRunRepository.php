<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecommendationRun>
 */
final class RecommendationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRun::class);
    }

    /**
     * The run a poll-driven tick should keep advancing, if any.
     */
    public function findActiveForUser(User $user): ?RecommendationRun
    {
        /** @var RecommendationRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.status IN (:active)')->setParameter('active', [
                RecommendationRun::STATUS_PENDING,
                RecommendationRun::STATUS_RUNNING,
            ])
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function findLatestForUser(User $user): ?RecommendationRun
    {
        /** @var RecommendationRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }
}
