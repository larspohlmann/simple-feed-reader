<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Hands out BeatDuringReleaseLocks over a real store, so a test can drive
 * RecommendationRunAdvancer::advance() and have the tick's own lock deliver a
 * beat at the moment it is released. See BeatDuringReleaseLock for what that
 * proves.
 */
final class BeatDuringReleaseLockFactory extends RecordingLockFactory
{
    public function __construct(
        PersistingStoreInterface $store,
        private readonly CompletionStreamHeartbeat $heartbeat,
    ) {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        return $this->record(
            $resource,
            $ttl,
            new BeatDuringReleaseLock(parent::createLock($resource, $ttl, $autoRelease), $this->heartbeat),
        );
    }

    /**
     * The last lock created for a resource, or null when none was. Every lock
     * this factory hands out is a BeatDuringReleaseLock, so the narrowing only
     * tells the type system what createLock() above already guarantees.
     */
    public function lastLockFor(string $resource): ?BeatDuringReleaseLock
    {
        $lock = $this->lastFor($resource)['lock'] ?? null;

        return $lock instanceof BeatDuringReleaseLock ? $lock : null;
    }
}
