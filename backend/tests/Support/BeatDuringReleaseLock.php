<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A real lock that beats the heartbeat as it is released and records its remaining lifetime either side of the beat.
 * RecommendationRunAdvancer::advance() disarms its keepalive before releasing, so the beat must change nothing; with
 * the order reversed the keepalive refreshes the lock back to a full TTL.
 */
final class BeatDuringReleaseLock implements SharedLockInterface
{
    private ?float $lifetimeBeforeTheBeat = null;

    private ?float $lifetimeAfterTheBeat = null;

    public function __construct(
        private readonly SharedLockInterface $lock,
        private readonly CompletionStreamHeartbeatInterface $heartbeat,
    ) {
    }

    public function release(): void
    {
        $this->lifetimeBeforeTheBeat = $this->lock->getRemainingLifetime();
        $this->heartbeat->beat();
        $this->lifetimeAfterTheBeat = $this->lock->getRemainingLifetime();

        $this->lock->release();
    }

    public function lifetimeBeforeTheBeat(): ?float
    {
        return $this->lifetimeBeforeTheBeat;
    }

    public function lifetimeAfterTheBeat(): ?float
    {
        return $this->lifetimeAfterTheBeat;
    }

    public function acquire(bool $blocking = false): bool
    {
        return $this->lock->acquire($blocking);
    }

    public function acquireRead(bool $blocking = false): bool
    {
        return $this->lock->acquireRead($blocking);
    }

    public function refresh(?float $ttl = null): void
    {
        $this->lock->refresh($ttl);
    }

    public function isAcquired(): bool
    {
        return $this->lock->isAcquired();
    }

    public function isExpired(): bool
    {
        return $this->lock->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }
}
