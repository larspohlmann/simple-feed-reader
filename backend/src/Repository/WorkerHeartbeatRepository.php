<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes are single statements, never a flush: a worker touches its heartbeat mid-tick, and a flush would commit
 * whatever else the tick holds dirty. Reads are arrays, so no managed copy goes stale behind a write.
 *
 * @extends ServiceEntityRepository<WorkerHeartbeat>
 */
final class WorkerHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerHeartbeat::class);
    }

    /** An upsert, not UPDATE-then-INSERT: MySQL counts an UPDATE to the same value as zero rows. */
    public function touch(string $name, \DateTimeImmutable $when): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = DatabasePlatform::isMySql($connection)
            ? 'ON DUPLICATE KEY UPDATE'
            : 'ON CONFLICT (name) DO UPDATE SET';

        $connection->executeStatement(
            sprintf('INSERT INTO worker_heartbeat (name, touched_at) VALUES (?, ?) %s touched_at = ?', $onConflict),
            [$name, $when, $when],
            [Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
        );
    }

    public function findTouchedAt(string $name): ?\DateTimeImmutable
    {
        return $this->findTouchedAtByNames([$name])[$name] ?? null;
    }

    /**
     * One query for every name, because the poll path asks about every driver kind on every request.
     *
     * @param list<string> $names
     *
     * @return array<string, \DateTimeImmutable> names without a row are absent
     */
    public function findTouchedAtByNames(array $names): array
    {
        /** @var list<array{name: string, touchedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('heartbeat')
            ->select('heartbeat.name AS name', 'heartbeat.touchedAt AS touchedAt')
            ->andWhere('heartbeat.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'touchedAt', 'name');
    }

    /** Idempotent by design: the drain command forgets from both its `finally` and its shutdown hook (#371). */
    public function forget(string $name): void
    {
        $this->createQueryBuilder('heartbeat')
            ->delete()
            ->where('heartbeat.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->execute();
    }
}
