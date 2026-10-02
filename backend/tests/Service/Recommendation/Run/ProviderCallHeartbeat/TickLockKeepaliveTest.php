<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\ProviderCallHeartbeat;

use App\Service\Recommendation\Run\ProviderCallHeartbeat\TickLockKeepalive;
use App\Tests\Support\RefreshCountingLock;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(TickLockKeepalive::class)]
final class TickLockKeepaliveTest extends TestCase
{
    private const string LOCK_RESOURCE = 'recommendation-run-1';

    /** Through hold() then release(), so a broken null-lock guard would refresh a lock that really exists. */
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

    public function testAResetBetweenMessagesDisarmsIt(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();
        $keepalive->hold($lock, self::LOCK_RESOURCE);

        $keepalive->reset();
        $keepalive->beat();

        self::assertSame(0, $lock->refreshCount());
    }

    /**
     * Beats arrive many times a second and each refresh is a lock-store call, so they are throttled, at an interval
     * far below the lock's TTL.
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
     * A beat exactly on the interval refreshes: `>` instead of `>=` would add a whole interval of silence that nothing
     * else notices.
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
     * A refresh rejected because someone else holds the lock means the double-bank is underway. beat() runs inside
     * the streaming loop and may not throw, so the loss is recorded for the tick checkpoint.
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

        $records = $logSpy->getRecords();
        self::assertCount(
            1,
            $records,
            'A stolen lock must be logged too: it is how a reader finds out afterwards.',
        );
        self::assertSame(
            TickLockKeepalive::LOCK_TAKEN_MESSAGE,
            $records[0]->message,
            'A stolen lock must not read like a store blip -- #439 was diagnosed from this line.',
        );
        self::assertSame(self::LOCK_RESOURCE, $records[0]->context['resource']);
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
        self::assertSame(
            TickLockKeepalive::REFRESH_FAILED_MESSAGE,
            $records[0]->message,
            'A store that could not answer must not be reported as a stolen lock.',
        );
        self::assertSame(self::LOCK_RESOURCE, $records[0]->context['resource']);
        self::assertArrayHasKey('exception', $records[0]->context);
    }
}
