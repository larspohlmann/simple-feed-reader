<?php

declare(strict_types=1);

namespace App\Service\Queue\Model;

final readonly class QueueBacklogModel
{
    private function __construct(
        public int $waiting,
        public ?\DateTimeImmutable $oldestQueuedAt,
    ) {
    }

    /** A `COUNT`/`MIN` row as either driver returns it; the stored time is naive UTC. */
    public static function fromAggregate(int|string $waiting, ?string $oldestQueuedAt): self
    {
        return new self(
            (int) $waiting,
            $oldestQueuedAt === null ? null : new \DateTimeImmutable($oldestQueuedAt, new \DateTimeZone('UTC')),
        );
    }

    public static function none(): self
    {
        return new self(0, null);
    }

    public function named(QueueName $queue): QueueDepthModel
    {
        return new QueueDepthModel($queue, $this);
    }
}
