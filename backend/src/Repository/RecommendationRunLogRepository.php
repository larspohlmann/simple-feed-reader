<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\AiRunKind;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RequestAdjustment;
use App\Repository\Exception\RecordNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads are shaped for the ~2 s debug poll: the list query hydrates no
 * LONGTEXT at all (sizes come from SQL LENGTH()).
 *
 * @phpstan-type DebugLogRow array{id: int, runId: int, phase: CallPhase, batchNumber: ?int, attempt: int,
 *     verdict: ?CallVerdict, requestBytes: int, responseBytes: int, wireBytes: int,
 *     createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable, errorDetail: ?string, finishReason: ?string,
 *     adjustedSetting: ?RequestAdjustment, adjustedValue: ?string}
 *
 * @extends ServiceEntityRepository<RecommendationRunLog>
 */
final class RecommendationRunLogRepository extends ServiceEntityRepository
{
    private const string OWNER_RUN = 'run';
    private const string OWNER_PROFILE_RUN = 'profileRun';
    private const string OWNER_STORY_AI_RUN = 'storyAiRun';
    private const string OWNER_INTEREST_TERM_RUN = 'interestTermRun';

    public function __construct(ManagerRegistry $registry, private readonly RowIds $rowIds)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /** @return list<DebugLogRow> */
    public function listForRun(User $user, AiRunKind $kind, int $runId): array
    {
        return $this->findListRows($user, self::resolveOwner($kind), $runId);
    }

    /** @return self::OWNER_* */
    private static function resolveOwner(AiRunKind $kind): string
    {
        return match ($kind) {
            AiRunKind::ForYou => self::OWNER_RUN,
            AiRunKind::Profile => self::OWNER_PROFILE_RUN,
            AiRunKind::Story => self::OWNER_STORY_AI_RUN,
        };
    }

    /**
     * @param self::OWNER_* $owner
     *
     * @return list<DebugLogRow>
     */
    private function findListRows(User $user, string $owner, int $ownerId): array
    {
        /** @var list<array{id: int, runId: int, phase: CallPhase, batchNumber: ?int, attempt: int,
         *     verdict: ?CallVerdict, requestBytes: int|string, responseBytes: int|string,
         *     wireBytes: int, createdAt: \DateTimeImmutable, finishedAt: ?\DateTimeImmutable,
         *     errorDetail: ?string, finishReason: ?string, adjustedSetting: ?RequestAdjustment,
         *     adjustedValue: ?string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                \sprintf('IDENTITY(l.%s) AS runId', $owner),
                'l.phase AS phase',
                'l.batchNumber AS batchNumber',
                'l.attempt AS attempt',
                'l.outcome.verdict AS verdict',
                'LENGTH(l.requestBody) AS requestBytes',
                'LENGTH(l.responseText) AS responseBytes',
                'l.outcome.wireBytes AS wireBytes',
                'l.createdAt AS createdAt',
                'l.outcome.finishedAt AS finishedAt',
                'l.failure.errorDetail AS errorDetail',
                'l.outcome.finishReason AS finishReason',
                'l.failure.adjustedSetting AS adjustedSetting',
                'l.failure.adjustedValue AS adjustedValue',
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
                'adjustedSetting' => $row['adjustedSetting'],
                'adjustedValue' => $row['adjustedValue'],
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
    public function streamingTextForRun(User $user, AiRunKind $kind, int $runId): array
    {
        return $this->findStreamingTexts($user, self::resolveOwner($kind), $runId);
    }

    /**
     * @param self::OWNER_* $owner
     *
     * @return array<int, string>
     */
    private function findStreamingTexts(User $user, string $owner, int $ownerId): array
    {
        /** @var list<array{id: int, responseText: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.responseText AS responseText')
            ->join('l.' . $owner, 'r')
            ->where('r.user = :user')
            ->andWhere('r.id = :owner')
            ->andWhere('l.outcome.verdict IS NULL')
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
            ->leftJoin('l.storyAiRun', 's')
            ->leftJoin('l.interestTermRun', 't')
            ->where('l.id = :id')
            ->andWhere('r.user = :user OR p.user = :user OR s.user = :user OR t.user = :user')
            ->setParameter('id', $logId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $log ?? throw new RecordNotFoundException('No such debug log entry.');
    }

    /**
     * Drops the account's recommendation-run rows except those of the named runs, the retention window at the start
     * of a run; an empty keep list drops them all. Profile, interest-term and story AI run rows stay.
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
     * Drops the account's story AI run rows except those of the named runs; an empty keep list drops them all.
     *
     * @param list<int> $keptStoryAiRunIds
     */
    public function deleteForUserOutsideStoryAiRuns(User $user, array $keptStoryAiRunIds): void
    {
        $this->rowIds->delete(
            RecommendationRunLog::class,
            $this->idsForUser($user, self::OWNER_STORY_AI_RUN, $keptStoryAiRunIds),
        );
    }

    /**
     * Drops the account's interest-term run rows except those of the named runs; an empty keep list drops them all.
     *
     * @param list<int> $keptInterestTermRunIds
     */
    public function deleteForUserOutsideInterestTermRuns(User $user, array $keptInterestTermRunIds): void
    {
        $this->rowIds->delete(
            RecommendationRunLog::class,
            $this->idsForUser($user, self::OWNER_INTEREST_TERM_RUN, $keptInterestTermRunIds),
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
