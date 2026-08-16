<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
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
 * it can produce instead, and a dead holder stops refreshing within one beat.
 * Task 9 arms this and resizes the TTL against that silence; this class only
 * does the refreshing, but the number the resize must respect is NOT
 * MINIMUM_INTERVAL_SECONDS -- a beat only fires from inside
 * OpenAiCompatibleChatClient::completeMany(), once per chunk the stream
 * yields, so the real ceiling is set by everything that produces no chunk:
 *
 * - A silent provider yields nothing until HttpClient's idle timeout, i.e.
 *   ProviderTimeouts::$firstByteSeconds -- 900 s on the slow profile.
 * - Nothing beats at all between acquire() and the first request: candidate
 *   loading and prompt assembly run first.
 * - Nothing beats between calls or waves either, while a batch's winners are
 *   ranked, banked and recorded.
 *
 * A TTL sized from the 30 s throttle instead of from firstByteSeconds would
 * let a live slow-profile holder's lock lapse mid-call, and a second tick
 * could then steal it -- the exact double-bank this class exists to prevent.
 *
 * A refresh lost to LockConflictedException means a second process has
 * already re-acquired the lock -- the double-bank is not a risk at that
 * point, it is underway. beat() never throws it on, deliberately: throwing
 * into the streaming loop is the failure this class exists to avoid, and the
 * tick is mid-HTTP-call with nowhere safe to unwind to. It records the loss
 * instead, and RecommendationCancellationCheckpoint reads it: the tick stops
 * at its next checkpoint rather than keep banking against a lock it has
 * already lost. Deciding what to do about it stays there; all this class
 * knows is that the store said the lock is somebody else's.
 *
 * Not readonly: the held lock is the point.
 */
final class TickLockKeepalive implements CompletionStreamHeartbeat
{
    public const int MINIMUM_INTERVAL_SECONDS = 30;

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
     * Arms the keepalive for the duration of one tick. $resource is the
     * lock's own name (RecommendationRunAdvancer's per-user key) rather than
     * something read back off $lock -- LockInterface exposes no such
     * accessor -- and exists only so a lost refresh can be logged against the
     * run it stranded instead of an anonymous lock object. The throttle
     * resets with the arming: a fresh tick must never inherit the previous
     * tick's beat clock, or the new lock goes unrefreshed for up to
     * MINIMUM_INTERVAL_SECONDS for a reason that has nothing to do with it.
     * A recorded loss resets with it for the same reason: one instance serves
     * every tick a worker runs, and the tick that lost its lock is over.
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
     * RecommendationCancellationCheckpoint asks, and stops the tick there.
     * The fact travels as a field rather than as an exception because beat()
     * fires from inside the streaming loop, where the tick has nowhere safe
     * to unwind to.
     *
     * Cleared in exactly one place, hold(), because the loss belongs to the
     * tick that suffered it and hold() is where the next tick begins.
     * release() leaves it alone: nothing consults a disarmed keepalive, so a
     * second clearing point would only be a second thing to keep in step.
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
            $this->report($conflict);
        } catch (LockExceptionInterface $failure) {
            // A store that could not answer says nothing about who owns the
            // lock, and the tick is still working, so it keeps going.
            $this->report($failure);
        }
    }

    private function report(LockExceptionInterface $failure): void
    {
        $this->logger->warning(
            'Could not refresh the recommendation tick lock',
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
