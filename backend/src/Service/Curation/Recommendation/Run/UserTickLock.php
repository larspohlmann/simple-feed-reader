<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\User;
use App\Service\Recommendation\Run\Pass\HeldTickLock;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use Symfony\Component\Lock\LockFactory;

/** The per-user lock every tick takes, a recommendation run's or a profile run's, so the two take turns. */
final readonly class UserTickLock
{
    private const string LOCK_NAME_PREFIX = 'ai-recommendations-';

    public function __construct(
        private LockFactory $lockFactory,
        private TickLockKeepalive $keepalive,
    ) {
    }

    /** The one place the lock's name is formed; RecommendationPollDriver logs this very name. */
    public static function nameFor(User $user): string
    {
        return self::LOCK_NAME_PREFIX . $user->requireId();
    }

    /** Null when another tick holds the lock: the healthy, frequent case, so it is not logged here. */
    public function acquire(User $user, float $ttlSeconds): ?HeldTickLock
    {
        $lockName = self::nameFor($user);
        $lock = $this->lockFactory->createLock($lockName, $ttlSeconds);

        if (!$lock->acquire()) {
            return null;
        }

        // A hard request kill (Strato's 240 s cap) never reaches the caller's finally and would strand the lock for
        // its whole TTL. The delete is token-scoped, so on the normal path this hook is a harmless no-op.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // A failed release during shutdown must not raise a second fatal; the TTL still bounds the stall.
            }
        });

        $this->keepalive->hold($lock, $lockName);

        return new HeldTickLock($lock, $this->keepalive);
    }
}
