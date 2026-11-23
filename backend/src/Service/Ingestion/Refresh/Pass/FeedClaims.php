<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Pass;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/** The feeds one refresh pass holds, so two passes never fetch the same feed at once. */
final class FeedClaims
{
    private const string LOCK_PREFIX = 'feed-refresh-feed-';
    private const float LOCK_TTL_SECONDS = 60.0;

    /** @var array<int, SharedLockInterface> */
    private array $locksByFeedId = [];

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function claim(int $feedId): bool
    {
        $lock = $this->lockFactory->createLock(self::LOCK_PREFIX . $feedId, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return false;
        }

        $this->locksByFeedId[$feedId] = $lock;

        return true;
    }

    public function release(int $feedId): void
    {
        if (!isset($this->locksByFeedId[$feedId])) {
            return;
        }

        $this->locksByFeedId[$feedId]->release();
        unset($this->locksByFeedId[$feedId]);
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->locksByFeedId) as $feedId) {
            $this->release($feedId);
        }
    }
}
