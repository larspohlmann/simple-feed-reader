<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A real lock factory that also remembers the TTL each lock was asked for.
 *
 * The advancer sizes its per-user lock from the connection it is about to
 * call (#433), so the number is no longer a constant a test can read: it is
 * decided per tick. Recording what the tick actually asked for is the only
 * way to pin the invariant against the code that runs, rather than against a
 * re-derivation of it in the test.
 */
final class TtlRecordingLockFactory extends LockFactory
{
    /** @var list<array{resource: string, ttl: ?float}> */
    private array $requested = [];

    public function __construct(PersistingStoreInterface $store)
    {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        $this->requested[] = ['resource' => $resource, 'ttl' => $ttl];

        return parent::createLock($resource, $ttl, $autoRelease);
    }

    /**
     * The TTL of the last lock created for a resource, or null when none was.
     */
    public function lastTtlFor(string $resource): ?float
    {
        foreach (array_reverse($this->requested) as $lock) {
            if ($lock['resource'] === $resource) {
                return $lock['ttl'];
            }
        }

        return null;
    }
}
