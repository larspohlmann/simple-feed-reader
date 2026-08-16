<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Hands out BeatDuringReleaseLocks over a real store, so a test can drive
 * RecommendationRunAdvancer::advance() and have the tick's own lock deliver a
 * beat at the moment it is released. See BeatDuringReleaseLock for what that
 * proves.
 */
final class BeatDuringReleaseLockFactory extends LockFactory
{
    /** @var list<array{resource: string, lock: BeatDuringReleaseLock}> */
    private array $created = [];

    public function __construct(
        PersistingStoreInterface $store,
        private readonly CompletionStreamHeartbeat $heartbeat,
    ) {
        parent::__construct($store);
    }

    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
    {
        $lock = new BeatDuringReleaseLock(parent::createLock($resource, $ttl, $autoRelease), $this->heartbeat);
        $this->created[] = ['resource' => $resource, 'lock' => $lock];

        return $lock;
    }

    /**
     * The last lock created for a resource, or null when none was.
     */
    public function lastLockFor(string $resource): ?BeatDuringReleaseLock
    {
        foreach (array_reverse($this->created) as $created) {
            if ($created['resource'] === $resource) {
                return $created['lock'];
            }
        }

        return null;
    }
}
