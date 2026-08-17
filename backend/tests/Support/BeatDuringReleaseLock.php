<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\CompletionStreamHeartbeat;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * A real lock that beats the transport's heartbeat in the instant it is
 * released, and remembers its own remaining lifetime either side of that
 * beat.
 *
 * RecommendationRunAdvancer::advance() disarms its keepalive before it
 * releases the lock, and the comment there says why: a beat landing between
 * the two would refresh a lock that is on its way out. A test cannot
 * ordinarily insert itself between two adjacent statements — but it can make
 * the second statement itself deliver the beat, which puts the beat exactly
 * in the window the ordering defends. With the ordering right the keepalive
 * is already disarmed and the beat changes nothing; reversed, the keepalive
 * still holds this lock and refreshes it, and the lifetime jumps back to a
 * full TTL.
 */
final class BeatDuringReleaseLock implements SharedLockInterface
{
    private ?float $lifetimeBeforeTheBeat = null;

    private ?float $lifetimeAfterTheBeat = null;

    public function __construct(
        private readonly SharedLockInterface $lock,
        private readonly CompletionStreamHeartbeat $heartbeat,
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
