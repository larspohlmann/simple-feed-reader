<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The run log read behind the phase-weighted time-left estimate (#638): for an
 * account's most recent completed runs, the wall-clock span of each phase and
 * how many batches the batch phase covered. {@see PhaseDurations} turns these
 * spans into the averages the estimate weights its remaining work by.
 *
 * A span is derived in PHP from MIN(createdAt)/MAX(finishedAt) rather than in
 * SQL, so no dialect-specific date arithmetic leaks into the query — the suite
 * runs it on both SQLite and MySQL.
 *
 * @extends ServiceEntityRepository<RecommendationRunLog>
 */
final class RecommendationRunTimingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /**
     * @return list<array{runId: int, phase: string, spanSeconds: float, batchCount: int}>
     */
    public function completedRunPhaseSpans(User $user, int $limit): array
    {
        $runIds = $this->newestCompletedRunIds($user, $limit);
        if ([] === $runIds) {
            return [];
        }

        /** @var list<array{runId: int|string, phase: string, startedAt: string, finishedAt: string,
         *     batchCount: int|string}> $rows */
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(l.run) AS runId, l.phase AS phase,'
            . ' MIN(l.createdAt) AS startedAt, MAX(l.finishedAt) AS finishedAt,'
            . ' COUNT(DISTINCT l.batchNumber) AS batchCount'
            . ' FROM ' . RecommendationRunLog::class . ' l'
            . ' WHERE IDENTITY(l.run) IN (:runIds) AND l.finishedAt IS NOT NULL'
            . ' GROUP BY l.run, l.phase',
        )->setParameter('runIds', $runIds)->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'runId' => (int) $row['runId'],
                'phase' => $row['phase'],
                'spanSeconds' => self::spanSeconds($row['startedAt'], $row['finishedAt']),
                'batchCount' => (int) $row['batchCount'],
            ],
            $rows,
        );
    }

    /**
     * @return list<int>
     */
    private function newestCompletedRunIds(User $user, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('r.id AS id')
            ->from(RecommendationRun::class, 'r')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.status = :completed')
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    private static function spanSeconds(string $startedAt, string $finishedAt): float
    {
        return (float) (
            (new \DateTimeImmutable($finishedAt))->getTimestamp()
            - (new \DateTimeImmutable($startedAt))->getTimestamp()
        );
    }
}
