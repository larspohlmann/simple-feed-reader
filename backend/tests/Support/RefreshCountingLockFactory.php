<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/** Hands out the sweep's feed-refresh lock as a RefreshCountingLock over a real one, and real locks for every feed claim. */
final class RefreshCountingLockFactory extends RecordingLockFactory
{
    private const string SWEEP_LOCK = 'feed-refresh';

    /** @var \Closure(RefreshCountingLock): void */
    private \Closure $armSweepLock;

    public function __construct(PersistingStoreInterface $store)
    {
        parent::__construct($store);
        $this->armSweepLock = static function (RefreshCountingLock $lock): void {
        };
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        $lock = parent::createLock($resource, $ttl, $autoRelease);
        if (self::SWEEP_LOCK !== $resource) {
            return $this->record($resource, $ttl, $lock);
        }

        $sweepLock = new RefreshCountingLock($lock);
        ($this->armSweepLock)($sweepLock);

        return $this->record($resource, $ttl, $sweepLock);
    }

    /** The sweep's lock is created inside run(), so its one-shot failure is armed as it is handed out. */
    public function conflictOnTheFirstRenewal(): void
    {
        $this->armSweepLock = static function (RefreshCountingLock $lock): void {
            $lock->conflictOnNextRefresh();
        };
    }

    public function throwOnTheFirstRenewal(): void
    {
        $this->armSweepLock = static function (RefreshCountingLock $lock): void {
            $lock->throwOnNextRefresh();
        };
    }

    /** The last lock created for a resource, or null when none was. */
    public function lastLockFor(string $resource): ?RefreshCountingLock
    {
        $lock = $this->lastFor($resource)['lock'] ?? null;

        return $lock instanceof RefreshCountingLock ? $lock : null;
    }
}
