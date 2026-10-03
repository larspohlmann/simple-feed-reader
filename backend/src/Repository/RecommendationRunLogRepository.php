<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads are shaped for the ~2 s debug poll: the list query hydrates no
 * LONGTEXT at all (sizes come from SQL LENGTH()).
 *
 * @phpstan-type DebugLogRow array{id: int, runId: int, phase: CallPhase, batchNumber: ?int, attempt: int,
 *     verdict: ?CallVerdict, requestBytes: int, responseBytes: int, wireBytes: int,
 *     createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable, errorDetail: ?string, finishReason: ?string}
 *
 * @extends ServiceEntityRepository<RecommendationRunLog>
 */
final class RecommendationRunLogRepository extends ServiceEntityRepository
{
    private const string OWNER_RUN = 'run';
    private const string OWNER_PROFILE_RUN = 'profileRun';

    public function __construct(ManagerRegistry $registry, private readonly RowIds $rowIds)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /** @return list<DebugLogRow> */
    public function listForRun(User $user, int $runId): array
    {
        return $this->listRowsOf($user, self::OWNER_RUN, $runId);
    }

    /** @return list<DebugLogRow> the rows of one profile run; `runId` carries the profile run's id */
    public function listForProfileRun(User $user, int $profileRunId): array
    {
        return $this->listRowsOf($user, self::OWNER_PROFILE_RUN, $profileRunId);
    }

    /**
     * @param self::OWNER_* $owner
     *
     * @return list<DebugLogRow>
     */
    private function listRowsOf(User $user, string $owner, int $ownerId): array
    {
        /** @var list<array{id: int, runId: int, phase: CallPhase, batchNumber: ?int, attempt: int,
         *     verdict: ?CallVerdict, requestBytes: int|string, responseBytes: int|string,
         *     wireBytes: int, createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable,
         *     errorDetail: ?string, finishReason: ?string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                \sprintf('IDENTITY(l.%s) AS runId', $owner),
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
            ->join('l.' . $owner, 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :owner')
            ->setParameter('user', $user)
            ->setParameter('owner', $ownerId)
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
                'createdAt' => $row['createdAt'],
                'finishedAt' => $row['finishedAt'],
                'errorDetail' => $row['errorDetail'],
                'finishReason' => $row['finishReason'],
            ],
            $rows,
        );
    }

    /**
     * The attempts one call has recorded, scoped to run, phase and batch number. Distill and consolidate have no batch
     * number, and `= NULL` never matches, so that case needs `IS NULL`.
     */
    public function countAttempts(RecommendationRun $run, CallPhase $phase, ?int $batchNumber): int
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

    public function countProfileRunAttempts(ProfileRun $profileRun): int
    {
        /** @var int|string $count */
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.profileRun = :profileRun')
            ->setParameter('profileRun', $profileRun)
            ->getQuery()
            ->getSingleScalarResult();

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
        return $this->streamingTextOf($user, self::OWNER_RUN, $runId);
    }

    /** @return array<int, string> log id => response text so far */
    public function streamingTextForProfileRun(User $user, int $profileRunId): array
    {
        return $this->streamingTextOf($user, self::OWNER_PROFILE_RUN, $profileRunId);
    }

    /**
     * @param self::OWNER_* $owner
     *
     * @return array<int, string>
     */
    private function streamingTextOf(User $user, string $owner, int $ownerId): array
    {
        /** @var list<array{id: int, responseText: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.responseText AS responseText')
            ->join('l.' . $owner, 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :owner')
            ->andWhere('l.verdict IS NULL')
            ->setParameter('user', $user)
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getArrayResult();

        $textById = [];
        foreach ($rows as $row) {
            $textById[$row['id']] = $row['responseText'];
        }

        return $textById;
    }

    public function getOneForUser(User $user, int $logId): RecommendationRunLog
    {
        /** @var RecommendationRunLog|null $log */
        $log = $this->createQueryBuilder('l')
            ->leftJoin('l.run', 'r')
            ->leftJoin('l.profileRun', 'p')
            ->where('l.id = :id')
            ->andWhere('r.user = :user OR p.user = :user')
            ->setParameter('id', $logId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $log ?? throw new RecordNotFoundException('No such debug log entry.');
    }

    /** The account's recommendation-run rows; profile-run rows are not this purge's. */
    public function deleteForUser(User $user): void
    {
        $this->rowIds->delete(RecommendationRunLog::class, $this->idsForUser($user, self::OWNER_RUN, []));
    }

    /**
     * Drops every log row of the account except those of the named runs, the retention window at the start of a run.
     * An empty keep list is a full wipe, which is what deleteForUser() asks for.
     *
     * @param list<int> $keptRunIds
     */
    public function deleteForUserOutsideRuns(User $user, array $keptRunIds): void
    {
        $this->rowIds->delete(RecommendationRunLog::class, $this->idsForUser($user, self::OWNER_RUN, $keptRunIds));
    }

    /**
     * Drops the account's profile-run rows except those of the named profile runs; recommendation-run rows stay.
     *
     * @param list<int> $keptProfileRunIds
     */
    public function deleteForUserOutsideProfileRuns(User $user, array $keptProfileRunIds): void
    {
        $this->rowIds->delete(
            RecommendationRunLog::class,
            $this->idsForUser($user, self::OWNER_PROFILE_RUN, $keptProfileRunIds),
        );
    }

    /**
     * @param self::OWNER_* $owner
     * @param list<int> $keptOwnerIds
     *
     * @return list<int>
     */
    private function idsForUser(User $user, string $owner, array $keptOwnerIds): array
    {
        $query = $this->createQueryBuilder('l')
            ->join('l.' . $owner, 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user);

        if ([] !== $keptOwnerIds) {
            $query->andWhere('r.id NOT IN (:kept)')->setParameter('kept', $keptOwnerIds);
        }

        return $this->rowIds->selectedBy($query);
    }
}
