<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
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

    private function activeStatusQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:active)')->setParameter('active', RunStatus::active());
    }

    /**
     * The run a poll-driven tick should keep advancing, if any.
     */
    public function findActiveForUser(User $user): ?RecommendationRun
    {
        /** @var RecommendationRun|null $run */
        $run = $this->activeStatusQuery()
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Read as a scalar, so the identity map cannot answer with the copy the caller is itself mutating:
     * RecommendationTickCheckpoint must learn whether another process stopped the run meanwhile.
     */
    public function statusOf(int $runId): ?RunStatus
    {
        /** @var string|null $status */
        $status = $this->createQueryBuilder('r')
            ->select('r.status')
            ->andWhere('r.id = :id')->setParameter('id', $runId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return null === $status ? null : RunStatus::from($status);
    }

    /**
     * One id at most, not a fetch: the terminate listener asks on every request. It scans, as no index leads with
     * `status`; at one row per generation per account that is the right trade, so it is neither cached nor indexed.
     */
    public function hasActiveRun(): bool
    {
        return [] !== $this->activeStatusQuery()
            ->select('r.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * The account's newest run, or its newest of one status: the for-you summary wants the newest completed run, the
     * one that produced the surviving list, whatever a later run did.
     */
    public function findLatestForUser(User $user, ?RunStatus $status = null): ?RecommendationRun
    {
        $query = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1);

        if (null !== $status) {
            $query->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /** @var RecommendationRun|null $run */
        $run = $query->getQuery()->getOneOrNullResult();

        return $run;
    }

    /**
     * A firing lasts the sum of its runs, each up to a provider timeout, so one firing ticks at most this many. Oldest
     * first keeps a capped sweep fair: a run leaves the set only by finishing, so every later run reaches the window.
     */
    private const int MAXIMUM_RUNS_PER_SWEEP = 10;

    /**
     * Every run the worker sweep should tick this firing, oldest first so one
     * account's run never starves another's behind it.
     *
     * @return list<RecommendationRun>
     */
    public function findAllActive(): array
    {
        /** @var list<RecommendationRun> $runs */
        $runs = $this->activeStatusQuery()
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(self::MAXIMUM_RUNS_PER_SWEEP)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * The account's newest runs, newest first: the retention window the debug log is trimmed to, and the runs the
     * debug panel offers to switch between.
     *
     * @return list<RecommendationRun>
     */
    public function findNewestForUser(User $user, int $limit): array
    {
        /** @var list<RecommendationRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * The same window as findNewestForUser(), as ids — what the log's
     * retention delete needs, without hydrating ten entities to read one
     * field off each.
     *
     * @return list<int>
     */
    public function findNewestIdsForUser(User $user, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.id AS id')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    /**
     * Runs carry no further children of their own by this point — the
     * caller deletes logs and items first — so this deletes directly by
     * user rather than the select-ids-then-delete shape those two need.
     */
    public function deleteForUser(User $user): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationRun r WHERE r.user = :user',
        )->setParameter('user', $user)->execute();
    }
}
