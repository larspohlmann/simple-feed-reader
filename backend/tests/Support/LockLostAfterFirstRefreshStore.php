<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Wraps a real lock store and lets exactly one `putOffExpiration()` call
 * through -- the one `Lock::acquire()` makes internally to seed the TTL --
 * before every later call throws, as if another process had already
 * re-acquired the same key. Pins RecommendationDrainCommand's handling of a
 * lock lost between sweeps (WorkerRunSweep::sweep() duration is the SUM
 * over every active run, so a sweep spanning many users can outrun
 * LOCK_TTL_SECONDS between the drain loop's own refresh() calls).
 */
final class LockLostAfterFirstRefreshStore implements PersistingStoreInterface
{
    private int $refreshCalls = 0;

    public function __construct(
        private readonly PersistingStoreInterface $inner,
    ) {
    }

    public function save(Key $key): void
    {
        $this->inner->save($key);
    }

    public function delete(Key $key): void
    {
        $this->inner->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->inner->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        ++$this->refreshCalls;
        if ($this->refreshCalls > 1) {
            throw new LockConflictedException('Simulated: another drainer already re-acquired the lock.');
        }

        $this->inner->putOffExpiration($key, $ttl);
    }
}
