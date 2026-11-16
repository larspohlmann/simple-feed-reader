<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/** An in-memory lock store that counts the expiry put-offs and can report the lock as taken over once acquiring is done. */
final class CountingLockStore implements PersistingStoreInterface
{
    public const int ACQUIRING_PUT_OFFS = 1;

    public int $putOffs = 0;

    private readonly InMemoryStore $inner;

    public function __construct(private readonly bool $lockLost = false)
    {
        $this->inner = new InMemoryStore();
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
        ++$this->putOffs;
        if ($this->lockLost && $this->putOffs > self::ACQUIRING_PUT_OFFS) {
            throw new LockConflictedException();
        }
        $this->inner->putOffExpiration($key, $ttl);
    }
}
