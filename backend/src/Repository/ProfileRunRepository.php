<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileRun;
use App\Entity\User;
use App\Enum\RunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfileRun>
 */
final class ProfileRunRepository extends ServiceEntityRepository
{
    private const int MAXIMUM_RUNS_PER_SWEEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileRun::class);
    }

    public function findActiveForUser(User $user): ?ProfileRun
    {
        /** @var ProfileRun|null $profileRun */
        $profileRun = $this->activeStatusQuery()
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profileRun;
    }

    public function findLatestForUser(User $user): ?ProfileRun
    {
        /** @var ProfileRun|null $profileRun */
        $profileRun = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profileRun;
    }

    /** @return list<ProfileRun> oldest first, so a capped sweep reaches every account in turn */
    public function findAllActive(): array
    {
        /** @var list<ProfileRun> $profileRuns */
        $profileRuns = $this->activeStatusQuery()
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(self::MAXIMUM_RUNS_PER_SWEEP)
            ->getQuery()
            ->getResult();

        return $profileRuns;
    }

    public function hasActiveRun(): bool
    {
        return [] !== $this->activeStatusQuery()
            ->select('p.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
    }

    public function latestCompletedFingerprintFor(User $user): ?string
    {
        /** @var list<array{fingerprint: ?string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.fingerprint AS fingerprint')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->andWhere('p.status = :completed')->setParameter('completed', RunStatus::Completed)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['fingerprint'] ?? null;
    }

    /** @return list<int> newest first */
    public function findNewestIdsForUser(User $user, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS id')
            ->andWhere('p.user = :user')->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    private function activeStatusQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:active)')->setParameter('active', RunStatus::active());
    }
}
