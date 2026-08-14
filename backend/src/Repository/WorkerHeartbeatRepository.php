<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerHeartbeat>
 */
final class WorkerHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerHeartbeat::class);
    }

    /**
     * A single worker process touches any given name, so there is never a
     * concurrent writer to race — find-or-create is enough and no upsert SQL
     * is needed.
     */
    public function touch(string $name, \DateTimeImmutable $when): void
    {
        $heartbeat = $this->find($name);

        if (null === $heartbeat) {
            $heartbeat = new WorkerHeartbeat($name, $when);
            $this->getEntityManager()->persist($heartbeat);
        } else {
            $heartbeat->touch($when);
        }

        $this->getEntityManager()->flush();
    }

    public function findTouchedAt(string $name): ?\DateTimeImmutable
    {
        return $this->find($name)?->getTouchedAt();
    }

    /**
     * The touch instants of the names that have a row, keyed by name; names
     * without one are simply absent. One query rather than one per name,
     * because the caller on the poll path asks about every driver kind on
     * every request from every open tab.
     *
     * @param list<string> $names
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function findTouchedAtByNames(array $names): array
    {
        $touchedAt = [];

        /** @var list<WorkerHeartbeat> $heartbeats */
        $heartbeats = $this->createQueryBuilder('heartbeat')
            ->andWhere('heartbeat.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();

        foreach ($heartbeats as $heartbeat) {
            $touchedAt[$heartbeat->getName()] = $heartbeat->getTouchedAt();
        }

        return $touchedAt;
    }

    /**
     * Removes the row rather than back-dating it, so "no worker" is the
     * absence of a heartbeat — the same state a host that never ran one is
     * in. A name that was never touched is already forgotten, so this is
     * idempotent by design: the drain command calls it from both its
     * `finally` and its shutdown hook (#371).
     */
    public function forget(string $name): void
    {
        $heartbeat = $this->find($name);

        if (null === $heartbeat) {
            return;
        }

        $this->getEntityManager()->remove($heartbeat);
        $this->getEntityManager()->flush();
    }
}
