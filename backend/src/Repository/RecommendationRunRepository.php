<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecommendationRun>
 */
final class RecommendationRunRepository extends ServiceEntityRepository
{
    /**
     * What "active" means for a recommendation run, in one place: no query
     * below may drift from the others about which statuses still need
     * ticking. activeStatusQuery() carries it to all three.
     *
     * @var list<string>
     */
    private const array ACTIVE_STATUSES = [
        RecommendationRun::STATUS_PENDING,
        RecommendationRun::STATUS_RUNNING,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRun::class);
    }

    /**
     * Every reading of "still needs ticking" starts here, so the filter is
     * written once and each caller adds only what makes it its own question:
     * one account's run, a count, or the sweep's ordered window.
     */
    private function activeStatusQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:active)')->setParameter('active', self::ACTIVE_STATUSES);
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
     * The run's status as the database holds it right now, deliberately read
     * as a scalar so the identity map cannot answer with the copy the caller
     * is itself mutating. RecommendationTickCheckpoint needs exactly that:
     * after a provider call it must learn whether someone else stopped the
     * run meanwhile, and a managed entity would simply hand back the stale
     * in-memory status.
     */
    public function statusOf(int $runId): ?string
    {
        /** @var string|null $status */
        $status = $this->createQueryBuilder('r')
            ->select('r.status')
            ->andWhere('r.id = :id')->setParameter('id', $runId)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return $status;
    }

    /**
     * Whether any run anywhere still needs driving. A count, not a fetch: the
     * terminate listener asks this on every request and must not pay for
     * hydration to learn the answer is no.
     *
     * The count itself is a scan, not an index seek: the only index on the
     * table leads with `user_id`, and this filters on `status` alone. That is
     * the right trade at this table's size -- one run row per generation, per
     * account -- and it is why the answer is not cached and why no index was
     * added for it.
     */
    public function hasActiveRun(): bool
    {
        $activeRunCount = $this->activeStatusQuery()
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $activeRunCount > 0;
    }

    /**
     * The account's newest run, optionally of one status only.
     *
     * The two readings are one query because they differ in nothing but that
     * filter: callers driving a run want whatever ran last, whatever became of
     * it, while the for-you summary wants the newest *completed* run — the one
     * that produced the surviving list, which a later failed run never touched.
     * Its id and completedAt drive the header's "Last refreshed" hint and the
     * for-you divider suppression.
     */
    public function findLatestForUser(User $user, ?string $status = null): ?RecommendationRun
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
     * How many runs one worker firing may tick. A firing's duration is the
     * SUM over the runs it touches, and one run can spend a whole provider
     * timeout, so an unbounded set turns a "ten-second" sweep into an
     * hour-long one. Oldest-first ordering keeps a capped sweep fair: a run
     * only leaves the set by completing or failing, so the head of the queue
     * drains under a bounded number of firings and every later run reaches
     * the window in turn -- first come, first served, and nobody starves.
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
     * The account's newest runs, newest first — the retention window the
     * debug log is trimmed to, and the list the debug panel offers to switch
     * between (#401).
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
