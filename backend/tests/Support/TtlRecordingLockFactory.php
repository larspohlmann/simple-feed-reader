<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Exposes the TTL each tick asked for and the lock it got: the advancer sizes its lock per tick from the connection it
 * calls, and a keepalive's refresh shows only in that very lock's remaining lifetime.
 */
final class TtlRecordingLockFactory extends RecordingLockFactory
{
    public function __construct(PersistingStoreInterface $store)
    {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->record($resource, $ttl, parent::createLock($resource, $ttl, $autoRelease));
    }

    /**
     * The TTL of the last lock created for a resource, or null when none was.
     */
    public function lastTtlFor(string $resource): ?float
    {
        return $this->lastFor($resource)['ttl'] ?? null;
    }

    /**
     * The last lock created for a resource, or null when none was.
     */
    public function lastLockFor(string $resource): ?SharedLockInterface
    {
        return $this->lastFor($resource)['lock'] ?? null;
    }
}
