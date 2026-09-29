<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Lets through only the putOffExpiration() that Lock::acquire() makes to seed the TTL; every later one throws with the
 * key left in place, as when another drainer has re-acquired it. The drainer's bid to retake it must lose.
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
