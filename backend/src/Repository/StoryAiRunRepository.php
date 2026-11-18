<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRunLog;
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

    /** Drops the call logs of the account's story AI runs beyond the newest $keptRuns; the runs are its cost record. */
    public function trimLogsToNewest(User $user, int $keptRuns): void
    {
        $keptRunIds = $this->newestIdsOf($user, $keptRuns);
        if ($keptRunIds === []) {
            return;
        }

        $this->getEntityManager()->createQuery(\sprintf(
            'DELETE FROM %s log WHERE IDENTITY(log.storyAiRun) IN'
            . ' (SELECT run.id FROM %s run WHERE run.user = :user AND run.id NOT IN (:kept))',
            RecommendationRunLog::class,
            StoryAiRun::class,
        ))->setParameter('user', $user)->setParameter('kept', $keptRunIds)->execute();
    }

    /** @return list<int> */
    private function newestIdsOf(User $user, int $limit): array
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
