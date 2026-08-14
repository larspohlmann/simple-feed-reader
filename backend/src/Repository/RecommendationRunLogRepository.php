<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads are shaped for the ~2 s debug poll: the list query hydrates no
 * LONGTEXT at all (sizes come from SQL LENGTH()), and only verdict-null
 * rows — the one call currently streaming — ship their partial text. Full
 * bodies load one row at a time via findOwned() when the user expands an
 * entry.
 *
 * @extends ServiceEntityRepository<RecommendationRunLog>
 */
final class RecommendationRunLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /**
     * @return list<array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
     *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}>
     */
    public function listForRun(User $user, int $runId): array
    {
        /** @var list<array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
         *     verdict: ?string, requestBytes: int|string, responseBytes: int|string,
         *     wireBytes: int, createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable,
         *     errorDetail: ?string, finishReason: ?string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                'IDENTITY(l.run) AS runId',
                'l.phase AS phase',
                'l.batchNumber AS batchNumber',
                'l.attempt AS attempt',
                'l.verdict AS verdict',
                'LENGTH(l.requestBody) AS requestBytes',
                'LENGTH(l.responseText) AS responseBytes',
                'l.wireBytes AS wireBytes',
                'l.createdAt AS createdAt',
                'l.finishedAt AS finishedAt',
                'l.errorDetail AS errorDetail',
                'l.finishReason AS finishReason',
            )
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :run')
            ->setParameter('user', $user)
            ->setParameter('run', $runId)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // LENGTH() comes back as a string on some drivers; the contract is int.
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'runId' => (int) $row['runId'],
                'phase' => $row['phase'],
                'batchNumber' => $row['batchNumber'],
                'attempt' => $row['attempt'],
                'verdict' => $row['verdict'],
                'requestBytes' => (int) $row['requestBytes'],
                'responseBytes' => (int) $row['responseBytes'],
                'wireBytes' => $row['wireBytes'],
                'createdAt' => $row['createdAt']->format(\DATE_ATOM),
                'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
                'errorDetail' => $row['errorDetail'],
                'finishReason' => $row['finishReason'],
            ],
            $rows,
        );
    }

    /**
     * How many attempts a given call has already recorded, so the caller can
     * number the next one. Scoped to the run (not the user), phase and batch
     * number — the dedup phase has no batch number, and SQL `= NULL` never
     * matches, so that case needs an explicit `IS NULL`.
     */
    public function countAttempts(RecommendationRun $run, string $phase, ?int $batchNumber): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.run = :run')
            ->andWhere('l.phase = :phase')
            ->setParameter('run', $run)
            ->setParameter('phase', $phase);

        if (null === $batchNumber) {
            $qb->andWhere('l.batchNumber IS NULL');
        } else {
            $qb->andWhere('l.batchNumber = :batchNumber')->setParameter('batchNumber', $batchNumber);
        }

        /** @var int|string $count */
        $count = $qb->getQuery()->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * The partial text of the call(s) still streaming — at most one row in
     * practice, since a run makes one provider call at a time.
     *
     * @return array<int, string> log id => response text so far
     */
    public function streamingTextForRun(User $user, int $runId): array
    {
        /** @var list<array{id: int, responseText: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.responseText AS responseText')
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :run')
            ->andWhere('l.verdict IS NULL')
            ->setParameter('user', $user)
            ->setParameter('run', $runId)
            ->getQuery()
            ->getArrayResult();

        $textById = [];
        foreach ($rows as $row) {
            $textById[$row['id']] = $row['responseText'];
        }

        return $textById;
    }

    public function findOwned(int $id, User $user): ?RecommendationRunLog
    {
        /** @var RecommendationRunLog|null $log */
        $log = $this->createQueryBuilder('l')
            ->join('l.run', 'r')
            ->where('l.id = :id')
            ->andWhere('r.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $log;
    }

    /**
     * Two-step (select ids, then delete) rather than a DELETE with a
     * subquery: portable across both suite dialects and trivially testable.
     */
    public function deleteForUser(User $user): void
    {
        $this->deleteIds($this->idsForUser($user, null));
    }

    /**
     * Drops every log row of the account except those belonging to the named
     * runs — the retention window at the start of a run (#401). An empty keep
     * list is a full wipe, which is what deleteForUser() asks for.
     *
     * @param list<int> $keptRunIds
     */
    public function deleteForUserOutsideRuns(User $user, array $keptRunIds): void
    {
        $this->deleteIds($this->idsForUser($user, $keptRunIds));
    }

    /**
     * @param ?list<int> $keptRunIds null keeps nothing
     *
     * @return list<int>
     */
    private function idsForUser(User $user, ?array $keptRunIds): array
    {
        $query = $this->createQueryBuilder('l')
            ->select('l.id AS id')
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user);

        if (null !== $keptRunIds && [] !== $keptRunIds) {
            $query->andWhere('r.id NOT IN (:kept)')->setParameter('kept', $keptRunIds);
        }

        /** @var list<int> $ids */
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');

        return $ids;
    }

    /** @param list<int> $ids */
    private function deleteIds(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationRunLog l WHERE l.id IN (:ids)',
        )->setParameter('ids', $ids)->execute();
    }
}
