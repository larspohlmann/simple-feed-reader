<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Refreshes the tick's run lock on streamed chunks, at most once per MINIMUM_INTERVAL_SECONDS. A refresh lost to
 * another process is recorded, never thrown mid-call; RecommendationTickCheckpoint stops the tick. TTL sizing:
 * docs/recommendations-runs.md#the-tick-lock
 */
final class TickLockKeepalive implements CompletionStreamHeartbeatInterface, ResetInterface
{
    public const int MINIMUM_INTERVAL_SECONDS = 30;

    /** Apart from the refresh failure on purpose: a store blip is noise, a taken lock is a run advanced twice. */
    public const string LOCK_TAKEN_MESSAGE = 'Another process took the recommendation tick lock';

    public const string REFRESH_FAILED_MESSAGE = 'Could not refresh the recommendation tick lock';

    private ?LockInterface $held = null;

    private ?string $resource = null;

    private ?\DateTimeImmutable $lastRefreshAt = null;

    private bool $lockLost = false;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Arms the keepalive for one tick; $resource is the lock's name, for the log only (LockInterface cannot report it).
     * The beat clock and a recorded loss reset too: one instance serves every tick a worker runs.
     */
    public function hold(LockInterface $lock, string $resource): void
    {
        $this->held = $lock;
        $this->resource = $resource;
        $this->lastRefreshAt = null;
        $this->lockLost = false;
    }

    /**
     * Disarms it. A tick that has ended is no longer evidence of anything,
     * and the lock it held may already be released -- a keepalive left armed
     * could refresh a lock this process no longer owns.
     */
    public function release(): void
    {
        $this->held = null;
        $this->resource = null;
        $this->lastRefreshAt = null;
    }

    public function reset(): void
    {
        $this->release();
    }

    /**
     * Whether the store said this tick's lock is somebody else's. A field, not an exception: beat() runs inside the
     * stream, with nowhere safe to unwind. Cleared only by hold(), which begins the next tick.
     */
    public function hasLostTheLock(): bool
    {
        return $this->lockLost;
    }

    public function beat(): void
    {
        if (null === $this->held) {
            return;
        }

        $now = $this->clock->now();
        if (!$this->isDue($now)) {
            return;
        }

        $this->lastRefreshAt = $now;

        try {
            $this->held->refresh();
        } catch (LockConflictedException $conflict) {
            // The store answered: the lock is another process's. The tick stops at its next checkpoint, not here.
            $this->lockLost = true;
            $this->report(self::LOCK_TAKEN_MESSAGE, $conflict);
        } catch (LockExceptionInterface $failure) {
            // A store that could not answer says nothing about who owns the
            // lock, and the tick is still working, so it keeps going.
            $this->report(self::REFRESH_FAILED_MESSAGE, $failure);
        }
    }

    private function report(string $message, LockExceptionInterface $failure): void
    {
        $this->logger->warning(
            $message,
            ['resource' => $this->resource, 'exception' => $failure],
        );
    }

    private function isDue(\DateTimeImmutable $now): bool
    {
        if (null === $this->lastRefreshAt) {
            return true;
        }

        return $now->getTimestamp() - $this->lastRefreshAt->getTimestamp() >= self::MINIMUM_INTERVAL_SECONDS;
    }
}
