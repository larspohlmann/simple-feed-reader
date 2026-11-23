<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Counts refresh() calls, so a keepalive test can pin when a beat refreshed. Without an inner lock every other method
 * answers as a held lock; with one, they and a refresh that does not fail delegate to it.
 */
final class RefreshCountingLock implements SharedLockInterface
{
    private int $refreshCount = 0;

    private bool $nextRefreshThrows = false;

    private bool $nextRefreshConflicts = false;

    public function __construct(private readonly ?SharedLockInterface $inner = null)
    {
    }

    public function acquire(bool $blocking = false): bool
    {
        return $this->inner?->acquire($blocking) ?? true;
    }

    public function acquireRead(bool $blocking = false): bool
    {
        return $this->inner?->acquireRead($blocking) ?? true;
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

        $this->inner?->refresh($ttl);
    }

    public function isAcquired(): bool
    {
        return $this->inner?->isAcquired() ?? true;
    }

    public function release(): void
    {
        $this->inner?->release();
    }

    public function isExpired(): bool
    {
        return $this->inner?->isExpired() ?? false;
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->inner?->getRemainingLifetime();
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
