<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A real lock factory that records what it hands out. The advancer creates, sizes, refreshes and releases its per-user
 * lock inside advance(), so this is a test's only seam on the TTL a tick asked for and the lifetime left afterwards.
 */
abstract class RecordingLockFactory extends LockFactory
{
    /** @var list<array{resource: string, ttl: ?float, lock: SharedLockInterface}> */
    private array $created = [];

    /**
     * Called by a subclass's createLock() with whatever lock it decided to
     * hand back, so what is recorded is always the object the caller got --
     * a decorator included, never the instance it wraps.
     */
    protected function record(string $resource, ?float $ttl, SharedLockInterface $lock): SharedLockInterface
    {
        $this->created[] = ['resource' => $resource, 'ttl' => $ttl, 'lock' => $lock];

        return $lock;
    }

    /**
     * The last lock created for a resource, with the TTL it was asked for, or
     * null when none was. A tick may create several locks under different
     * names, so the scan runs backwards from the newest and stops on a match.
     *
     * @return array{resource: string, ttl: ?float, lock: SharedLockInterface}|null
     */
    protected function lastFor(string $resource): ?array
    {
        foreach (array_reverse($this->created) as $created) {
            if ($created['resource'] === $resource) {
                return $created;
            }
        }

        return null;
    }
}
