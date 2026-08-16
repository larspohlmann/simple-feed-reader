<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A real lock factory that also remembers the TTL each lock was asked for,
 * and the lock it handed back.
 *
 * The advancer sizes its per-user lock from the connection it is about to
 * call (#433), so the number is no longer a constant a test can read: it is
 * decided per tick. Recording what the tick actually asked for is the only
 * way to pin the invariant against the code that runs, rather than against a
 * re-derivation of it in the test.
 *
 * The lock itself is kept for the same reason (#444): a tick's keepalive
 * refreshes a lock the tick created internally, and the remaining lifetime of
 * that very object is the only place a refresh shows up.
 */
final class TtlRecordingLockFactory extends LockFactory
{
    /** @var list<array{resource: string, ttl: ?float, lock: SharedLockInterface}> */
    private array $created = [];

    public function __construct(PersistingStoreInterface $store)
    {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        $lock = parent::createLock($resource, $ttl, $autoRelease);
        $this->created[] = ['resource' => $resource, 'ttl' => $ttl, 'lock' => $lock];

        return $lock;
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

    /**
     * @return array{resource: string, ttl: ?float, lock: SharedLockInterface}|null
     */
    private function lastFor(string $resource): ?array
    {
        foreach (array_reverse($this->created) as $created) {
            if ($created['resource'] === $resource) {
                return $created;
            }
        }

        return null;
    }
}
