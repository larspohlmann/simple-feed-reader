<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\TickLockKeepalive;
use App\Tests\Support\RefreshCountingLock;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The mechanism that lets RecommendationRunAdvancer's per-user lock TTL
 * shrink to the longest silence a live holder can produce, instead of the
 * longest call it can legally make (#439, #444).
 */
#[CoversClass(TickLockKeepalive::class)]
final class TickLockKeepaliveTest extends TestCase
{
    private const string LOCK_RESOURCE = 'recommendation-run-1';

    /**
     * The null-lock guard covers two states that both mean "nothing to
     * refresh": before the first hold() and after release(). Exercising it
     * through hold() then release() gives the assertion a lock that really
     * would have been touched had the guard broken, rather than one that was
     * never wired in at all.
     */
    public function testABeatWithNothingHeldRefreshesNothing(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, self::LOCK_RESOURCE);
        $keepalive->release();

        $keepalive->beat();

        self::assertSame(0, $lock->refreshCount());
    }

    public function testTheFirstBeatAfterHoldRefreshes(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();

        $keepalive->hold($lock, self::LOCK_RESOURCE);
        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());
    }

    /**
     * A streamed answer delivers deltas many times a second and each refresh
     * is a call to the lock store, so the beats are throttled. The throttle
     * has to stay far below the lock's own TTL, which the interval between
     * these two beats is.
     */
    public function testABeatFiveSecondsLaterDoesNotRefreshAgain(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->beat();
        $clock->sleep(5);
        $keepalive->beat();

        self::assertSame(
            1,
            $lock->refreshCount(),
            'A beat five seconds after the last refresh must not cost a second one.',
        );
    }

    /**
     * The interval is a minimum, not a strict gap: a beat exactly on the
     * boundary refreshes. Pinned because the difference between `>=` and `>`
     * here is one whole interval of extra silence in the worst case, and
     * nothing else would notice.
     */
    public function testABeatExactlyOnTheIntervalRefreshes(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->beat();
        $clock->sleep(TickLockKeepalive::MINIMUM_INTERVAL_SECONDS);
        $keepalive->beat();

        self::assertSame(2, $lock->refreshCount());
    }

    /**
     * A tick that has ended is no longer evidence of anything, and the lock
     * it held may already be released -- a keepalive left armed could
     * refresh a lock this process no longer owns.
     */
    public function testABeatAfterReleaseRefreshesNothing(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, self::LOCK_RESOURCE);
        $keepalive->beat();

        $keepalive->release();
        $clock->sleep(600);
        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());
    }

    /**
     * A fresh tick must never inherit the previous tick's beat clock: without
     * the reset, the elapsed time since the first lock's last refresh would
     * already satisfy the throttle and the second lock's opening seconds
     * would go unrefreshed for a reason that has nothing to do with it.
     */
    public function testHoldOnASecondLockResetsTheThrottle(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $firstLock = new RefreshCountingLock();
        $keepalive->hold($firstLock, self::LOCK_RESOURCE);
        $keepalive->beat();

        $secondLock = new RefreshCountingLock();
        $keepalive->hold($secondLock, 'recommendation-run-2');
        $keepalive->beat();

        self::assertSame(
            1,
            $secondLock->refreshCount(),
            'hold() on a second lock must reset the throttle so the new lock is refreshed at once.',
        );
    }

    /**
     * A refresh the store rejects because someone else holds the lock is the
     * one failure that is not a store problem: the double-bank is underway.
     * beat() still may not throw -- it runs inside the streaming loop -- so
     * the loss is recorded here for the tick's cancellation checkpoint to
     * find at the next safe place to stop.
     */
    public function testARefreshRejectedByAnotherOwnerRecordsTheLockAsLost(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $logSpy = new TestHandler();
        $keepalive = new TickLockKeepalive($clock, new Logger('test', [$logSpy]));
        $lock = new RefreshCountingLock();
        $lock->conflictOnNextRefresh();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->beat();

        self::assertTrue($keepalive->hasLostTheLock());
        self::assertCount(
            1,
            $logSpy->getRecords(),
            'A stolen lock must be logged too: it is how a reader finds out afterwards.',
        );
    }

    /**
     * A store that failed to answer says nothing about who owns the lock, and
     * the holder most likely still does. Stopping the tick on it would throw
     * away a paid-for provider call over a blip.
     */
    public function testAStoreFailureIsNotRecordedAsALostLock(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $lock->throwOnNextRefresh();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->beat();

        self::assertFalse($keepalive->hasLostTheLock());
    }

    public function testNothingIsLostBeforeAnyBeat(): void
    {
        $keepalive = new TickLockKeepalive(new MockClock('2026-08-16 12:00:00'), new Logger('test'));
        $keepalive->hold(new RefreshCountingLock(), self::LOCK_RESOURCE);

        self::assertFalse($keepalive->hasLostTheLock());
    }

    /**
     * The keepalive outlives a single tick -- a worker holds one instance for
     * every run it advances -- so a loss that belonged to a finished tick
     * must not stop the next one before it has done anything.
     */
    public function testHoldClearsTheLossOfThePreviousTick(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lostLock = new RefreshCountingLock();
        $lostLock->conflictOnNextRefresh();
        $keepalive->hold($lostLock, self::LOCK_RESOURCE);
        $keepalive->beat();
        $keepalive->release();

        $keepalive->hold(new RefreshCountingLock(), 'recommendation-run-2');

        self::assertFalse($keepalive->hasLostTheLock());
    }

    public function testALockExceptionFromRefreshDoesNotEscapeBeatAndIsLoggedWithTheResource(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $logSpy = new TestHandler();
        $keepalive = new TickLockKeepalive($clock, new Logger('test', [$logSpy]));
        $lock = new RefreshCountingLock();
        $lock->throwOnNextRefresh();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());

        $records = $logSpy->getRecords();
        self::assertCount(1, $records, 'A lost refresh must be logged, not merely swallowed.');
        self::assertSame(self::LOCK_RESOURCE, $records[0]->context['resource']);
        self::assertArrayHasKey('exception', $records[0]->context);
    }
}
