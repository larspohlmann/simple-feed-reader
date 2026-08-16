<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockInterface;

/**
 * Keeps the lock of a tick that is streaming from expiring under it (#439,
 * #444).
 *
 * RecommendationRunAdvancer's per-user lock has always been sized for the
 * longest legal call a holder could make -- three hours five minutes on the
 * slow timeout profile -- because nothing renewed it in between and a second
 * process taking the same lock mid-tick could double-bank winners and
 * double-bill provider spend. Twice now a worker has died mid-tick instead,
 * and the same TTL that guarded against a stolen lock stranded the run for
 * hours against a holder that no longer existed.
 *
 * A lock a live holder refreshes can be sized against the longest *silence*
 * it can produce instead: refresh throughout the call, and a dead holder
 * stops refreshing within one beat. Task 9 arms this and resizes the TTL; this
 * class only does the refreshing.
 *
 * Not readonly: the held lock is the point.
 */
final class TickLockKeepalive implements CompletionStreamHeartbeat
{
    public const int MINIMUM_INTERVAL_SECONDS = 30;

    private ?LockInterface $held = null;

    private ?\DateTimeImmutable $lastRefreshAt = null;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Arms the keepalive for the duration of one tick. The throttle resets
     * with it: a fresh tick must never inherit the previous tick's beat
     * clock, or the new lock goes unrefreshed for up to
     * MINIMUM_INTERVAL_SECONDS for a reason that has nothing to do with it.
     */
    public function hold(LockInterface $lock): void
    {
        $this->held = $lock;
        $this->lastRefreshAt = null;
    }

    /**
     * Disarms it. A tick that has ended is no longer evidence of anything,
     * and the lock it held may already be released -- a keepalive left armed
     * could refresh a lock this process no longer owns.
     */
    public function release(): void
    {
        $this->held = null;
        $this->lastRefreshAt = null;
    }

    public function beat(): void
    {
        if (null === $this->held || !$this->isDue()) {
            return;
        }

        $this->lastRefreshAt = $this->clock->now();

        try {
            $this->held->refresh();
        } catch (LockExceptionInterface $failure) {
            // The tick is still working and its own release still runs. A
            // lock lost here means the TTL lapsed, which the refresh exists
            // to prevent -- worth a line, not worth a second failure inside
            // a streaming loop.
            $this->logger->warning('Could not refresh the recommendation tick lock', ['exception' => $failure]);
        }
    }

    private function isDue(): bool
    {
        if (null === $this->lastRefreshAt) {
            return true;
        }

        return $this->clock->now()->getTimestamp() - $this->lastRefreshAt->getTimestamp()
            >= self::MINIMUM_INTERVAL_SECONDS;
    }
}
