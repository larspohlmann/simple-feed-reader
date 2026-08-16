<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecommendationRun>
 */
final class RecommendationRunRepository extends ServiceEntityRepository
{
    /**
     * What "active" means for a recommendation run, in one place: neither
     * query below may drift from the other about which statuses still need
     * ticking.
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
     * The run a poll-driven tick should keep advancing, if any.
     */
    public function findActiveForUser(User $user): ?RecommendationRun
    {
        /** @var RecommendationRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.status IN (:active)')->setParameter('active', self::ACTIVE_STATUSES)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    /**
     * The run's status as the database holds it right now, deliberately read
     * as a scalar so the identity map cannot answer with the copy the caller
     * is itself mutating. RecommendationRunAdvancer's cancellation checkpoint
     * needs exactly that: after a provider call it must learn whether someone
     * else stopped the run meanwhile, and a managed entity would simply hand
     * back the stale in-memory status.
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

    /**
     * The run that produced the surviving for-you list: the newest *completed*
     * run, distinct from findLatestForUser() which may return a failed run that
     * never touched the list. Its id and completedAt drive the header's "Last
     * refreshed" hint and the for-you divider suppression.
     */
    public function newestCompletedRun(User $user): ?RecommendationRun
    {
        /** @var RecommendationRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.status = :completed')
            ->setParameter('user', $user)
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

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
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:active)')->setParameter('active', self::ACTIVE_STATUSES)
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

    /**
     * How many runs the history endpoint answers with (#409). The list is a
     * spending record a human reads, not a dataset — fifty rows is more than
     * anyone scrolls, and totalCostNanoCredits() below is computed over every
     * run anyway, so this cap never makes the number on screen wrong.
     */
    public const int HISTORY_LIMIT = 50;

    /**
     * The account's whole spend, summed in the database over every run it
     * ever made — deliberately not over the page findNewestForUser() returns.
     * An account whose runs all went unpriced sums to null, which is the
     * honest answer: nothing reported a price, as opposed to everything
     * reporting zero.
     */
    public function totalCostNanoCredits(User $user): ?int
    {
        $total = $this->createQueryBuilder('r')
            // Task 4 extracted the seven columns into a `ProviderUsage`
            // embeddable (PHPMD TooManyFields). The *column* names are
            // unprefixed and unchanged, but the DQL field path is not:
            // `r.costNanoCredits` throws "has no field or association named".
            ->select('SUM(r.providerUsage.costNanoCredits)')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? null : (int) $total;
    }
}
