<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/** Hands out BeatDuringReleaseLocks over a real store, so the tick's own lock beats as advance() releases it. */
final class BeatDuringReleaseLockFactory extends RecordingLockFactory
{
    public function __construct(
        PersistingStoreInterface $store,
        private readonly CompletionStreamHeartbeatInterface $heartbeat,
    ) {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->record(
            $resource,
            $ttl,
            new BeatDuringReleaseLock(parent::createLock($resource, $ttl, $autoRelease), $this->heartbeat),
        );
    }

    /** The last lock created for a resource, or null when none was. */
    public function lastLockFor(string $resource): ?BeatDuringReleaseLock
    {
        $lock = $this->lastFor($resource)['lock'] ?? null;

        return $lock instanceof BeatDuringReleaseLock ? $lock : null;
    }
}
