<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Lock\LockInterface;

/** Counts refresh() calls; without an inner lock it answers as a held one, with one it delegates everything else. */
final class RenewalCountingLock implements LockInterface
{
    private int $renewals = 0;

    public function __construct(
        private readonly ?LockInterface $inner = null,
        private readonly ?\Throwable $refreshFailure = null,
    ) {
    }

    public function acquire(bool $blocking = false): bool
    {
        return $this->inner?->acquire($blocking) ?? true;
    }

    public function refresh(?float $ttl = null): void
    {
        ++$this->renewals;

        if (null !== $this->refreshFailure) {
            throw $this->refreshFailure;
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

    public function renewals(): int
    {
        return $this->renewals;
    }
}
