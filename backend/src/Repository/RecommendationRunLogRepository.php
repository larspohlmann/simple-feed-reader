<?php

declare(strict_types=1);

namespace App\Repository;

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
class RecommendationRunLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /**
     * @return list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int}>
     */
    public function listForUser(User $user): array
    {
        /** @var list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
         *     verdict: ?string, requestBytes: int|string, responseBytes: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                'l.phase AS phase',
                'l.batchNumber AS batchNumber',
                'l.attempt AS attempt',
                'l.verdict AS verdict',
                'LENGTH(l.requestBody) AS requestBytes',
                'LENGTH(l.responseText) AS responseBytes',
            )
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // LENGTH() comes back as a string on some drivers; the contract is int.
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'phase' => $row['phase'],
                'batchNumber' => $row['batchNumber'],
                'attempt' => $row['attempt'],
                'verdict' => $row['verdict'],
                'requestBytes' => (int) $row['requestBytes'],
                'responseBytes' => (int) $row['responseBytes'],
            ],
            $rows,
        );
    }

    /**
     * The partial text of the call(s) still streaming — at most one row in
     * practice, since a run makes one provider call at a time.
     *
     * @return array<int, string> log id => response text so far
     */
    public function streamingTextForUser(User $user): array
    {
        /** @var list<array{id: int, responseText: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.responseText AS responseText')
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->andWhere('l.verdict IS NULL')
            ->setParameter('user', $user)
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
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('l')
                ->select('l.id AS id')
                ->join('l.run', 'r')
                ->where('r.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationRunLog l WHERE l.id IN (:ids)',
        )->setParameter('ids', $ids)->execute();
    }
}
