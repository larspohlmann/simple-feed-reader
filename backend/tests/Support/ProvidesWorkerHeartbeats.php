<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\WorkerHeartbeatRepository;

trait ProvidesWorkerHeartbeats
{
    private function heartbeats(): WorkerHeartbeatRepository
    {
        $heartbeats = self::getContainer()->get(WorkerHeartbeatRepository::class);
        self::assertInstanceOf(WorkerHeartbeatRepository::class, $heartbeats);

        return $heartbeats;
    }
}
