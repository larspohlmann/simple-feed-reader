<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkerHeartbeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One named liveness signal. The worker's sweep touches its row every
 * firing; the poll driver treats a fresh row as "a worker owns execution".
 * This is an efficiency signal only — the per-user run lock stays the
 * correctness guarantee (#311).
 */
#[ORM\Entity(repositoryClass: WorkerHeartbeatRepository::class)]
#[ORM\Table(name: 'worker_heartbeat')]
class WorkerHeartbeat
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $touchedAt;

    public function __construct(string $name, \DateTimeImmutable $touchedAt)
    {
        $this->name = $name;
        $this->touchedAt = $touchedAt;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTouchedAt(): \DateTimeImmutable
    {
        return $this->touchedAt;
    }

    public function touch(\DateTimeImmutable $when): void
    {
        $this->touchedAt = $when;
    }
}
