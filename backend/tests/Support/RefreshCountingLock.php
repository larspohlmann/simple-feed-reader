<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;

/**
 * A lock double that counts refresh() calls instead of talking to a store, so
 * TickLockKeepaliveTest can pin exactly when a beat refreshed without a real
 * lock backend. Every other LockInterface method is a stub -- the keepalive
 * only ever calls refresh().
 */
final class RefreshCountingLock implements LockInterface
{
    private int $refreshCount = 0;

    private bool $nextRefreshThrows = false;

    private bool $nextRefreshConflicts = false;

    public function acquire(bool $blocking = false): bool
    {
        return true;
    }

    public function refresh(?float $ttl = null): void
    {
        ++$this->refreshCount;

        if ($this->nextRefreshThrows) {
            $this->nextRefreshThrows = false;

            throw new LockAcquiringException('Simulated: the store rejected the refresh.');
        }

        if ($this->nextRefreshConflicts) {
            $this->nextRefreshConflicts = false;

            throw new LockConflictedException('Simulated: another process holds this lock now.');
        }
    }

    public function isAcquired(): bool
    {
        return true;
    }

    public function release(): void
    {
    }

    public function isExpired(): bool
    {
        return false;
    }

    public function getRemainingLifetime(): ?float
    {
        return null;
    }

    public function refreshCount(): int
    {
        return $this->refreshCount;
    }

    /** A store that failed to answer: the holder may well still own the lock. */
    public function throwOnNextRefresh(): void
    {
        $this->nextRefreshThrows = true;
    }

    /** A store that answered plainly: someone else owns the lock now. */
    public function conflictOnNextRefresh(): void
    {
        $this->nextRefreshConflicts = true;
    }
}
