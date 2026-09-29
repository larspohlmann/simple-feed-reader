<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Fails the drain loop's own refresh() as a lapsed TTL does: the key is dropped, then the call throws, so the drainer's
 * bid to retake it must win. The refresh Lock::acquire() makes right after save() passes, or no drain could start.
 */
final class LockKeyExpiringBeforeEveryRefreshStore implements PersistingStoreInterface
{
    private bool $savedSinceLastRefresh = false;

    public function __construct(
        private readonly PersistingStoreInterface $inner,
    ) {
    }

    public function save(Key $key): void
    {
        $this->inner->save($key);
        $this->savedSinceLastRefresh = true;
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
        if ($this->savedSinceLastRefresh) {
            $this->savedSinceLastRefresh = false;
            $this->inner->putOffExpiration($key, $ttl);

            return;
        }

        $this->inner->delete($key);

        throw new LockConflictedException('Simulated: the key expired before this refresh.');
    }
}
