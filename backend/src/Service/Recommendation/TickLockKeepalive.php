<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockInterface;

/**
 * Keeps the lock of a streaming tick from expiring under it (#439, #444).
 *
 * RecommendationRunAdvancer's per-user lock used to be sized for the longest legal call a
 * holder could make (three hours five minutes on the slow profile), because nothing
 * renewed it and a second process taking the lock mid-tick could double-bank winners and
 * double-bill provider spend. Twice a worker died mid-tick instead, and that same TTL
 * stranded the run for hours against a holder that no longer existed.
 *
 * A lock a live holder refreshes can be sized against the longest *silence* it can
 * produce, and a dead holder stops refreshing within one beat. The advancer arms this
 * around its tick and sizes the TTL against that silence; this class only refreshes. The
 * number that sizing must respect is NOT MINIMUM_INTERVAL_SECONDS -- a beat fires only
 * from inside OpenAiCompatibleChatClient::completeMany(), once per chunk, so the real
 * ceiling is set by everything that produces no chunk:
 *
 * - A silent provider yields nothing until HttpClient's idle timeout,
 *   ProviderTimeouts::$firstByteSeconds -- 900 s on the slow profile.
 * - Nothing beats between acquire() and the first request: candidate loading and prompt
 *   assembly run first.
 * - Nothing beats between calls or waves, while winners are ranked, banked and recorded.
 *
 * A TTL sized from the 30 s throttle instead of firstByteSeconds would let a live
 * slow-profile holder's lock lapse mid-call for a second tick to steal -- the exact
 * double-bank this class exists to prevent.
 *
 * A refresh lost to LockConflictedException means a second process has already
 * re-acquired the lock: the double-bank is underway. beat() never throws it on -- the
 * tick is mid-HTTP-call with nowhere safe to unwind -- it records the loss instead, and
 * RecommendationTickCheckpoint reads it to stop the tick at its next checkpoint rather
 * than bank against a lock it has lost.
 *
 * Not readonly: the held lock is the point.
 */
final class TickLockKeepalive implements CompletionStreamHeartbeat
{
    public const int MINIMUM_INTERVAL_SECONDS = 30;

    /**
     * The two refreshes fail for materially different reasons and #439 was diagnosed
     * from the log, so they do not share a line. A store that could not answer is a
     * blip; a lock another process now holds is a run being advanced twice, and must
     * read that way to someone scanning the log, not via the exception class in context.
     */
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
     * Arms the keepalive for one tick. $resource is the lock's own name
     * (RecommendationRunAdvancer's per-user key), not read back off $lock --
     * LockInterface exposes no such accessor -- and exists only so a lost refresh logs
     * against the run it stranded, not an anonymous lock. The throttle resets with the
     * arming, since a fresh tick must not inherit the previous tick's beat clock, or the
     * new lock goes unrefreshed for up to MINIMUM_INTERVAL_SECONDS. A recorded loss
     * resets too: one instance serves every tick a worker runs, and the tick that lost
     * its lock is over.
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

    /**
     * Whether the store has told this tick that its lock is somebody else's.
     *
     * RecommendationTickCheckpoint asks, and stops the tick there. The fact travels as
     * a field rather than an exception because beat() fires from inside the streaming
     * loop, where the tick has nowhere safe to unwind to.
     *
     * Cleared only in hold(), because the loss belongs to the tick that suffered it and
     * hold() begins the next tick. release() leaves it alone: nothing consults a
     * disarmed keepalive, so a second clearing point would only be a thing to keep in step.
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
            // Not the store failing -- the store answering plainly that this
            // lock is another process's now. The tick must stop, but not
            // here: see the class docblock.
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
