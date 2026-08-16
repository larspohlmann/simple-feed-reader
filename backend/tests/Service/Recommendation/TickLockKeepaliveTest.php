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
    public function testABeatBeforeHoldRefreshesNothing(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();

        $keepalive->beat();

        self::assertSame(0, $lock->refreshCount());
    }

    public function testTheFirstBeatAfterHoldRefreshes(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $keepalive = new TickLockKeepalive($clock, new Logger('test'));
        $lock = new RefreshCountingLock();

        $keepalive->hold($lock);
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
        $keepalive->hold($lock);

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
        $keepalive->hold($lock);

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
        $keepalive->hold($lock);
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
        $keepalive->hold($firstLock);
        $keepalive->beat();

        $secondLock = new RefreshCountingLock();
        $keepalive->hold($secondLock);
        $keepalive->beat();

        self::assertSame(
            1,
            $secondLock->refreshCount(),
            'hold() on a second lock must reset the throttle so the new lock is refreshed at once.',
        );
    }

    public function testALockExceptionFromRefreshDoesNotEscapeBeatAndIsLogged(): void
    {
        $clock = new MockClock('2026-08-16 12:00:00');
        $logSpy = new TestHandler();
        $keepalive = new TickLockKeepalive($clock, new Logger('test', [$logSpy]));
        $lock = new RefreshCountingLock();
        $lock->throwOnNextRefresh();
        $keepalive->hold($lock);

        $keepalive->beat();

        self::assertSame(1, $lock->refreshCount());
        self::assertTrue($logSpy->hasWarningRecords(), 'A lost refresh must be logged, not merely swallowed.');
    }
}
