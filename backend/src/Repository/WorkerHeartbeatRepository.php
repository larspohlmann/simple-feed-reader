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

        if ($heartbeat === null) {
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
}
