<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Wraps a real lock store and makes the drain loop's own `refresh()` fail the
 * way a lapsed TTL fails: the key is dropped first, then the call throws.
 * Nobody else holds the key afterwards, so the drainer's bid to take it back
 * must win and the drain must carry on rather than abandon healthy in-flight
 * work to the once-a-minute cron (#371 final review, Finding 4b). That is the
 * case LockLostAfterFirstRefreshStore cannot express: there the key survives
 * the failure, and a second drainer really does own it.
 *
 * Only a refresh the *caller* makes may fail, which is what `$savedSinceLast`
 * separates out: `Lock::acquire()` calls `refresh()` itself right after
 * `save()` to seed the TTL, and failing that one would make every acquire
 * return false and no drain could ever start. A refresh with no save before
 * it is the drain loop's own.
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
