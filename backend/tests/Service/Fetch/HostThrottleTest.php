<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\HostThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class HostThrottleTest extends TestCase
{
    public function testAFreshHostIsFree(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        self::assertSame(0, $throttle->remainingSeconds('https://www.reddit.com/r/PHP/.rss'));
    }

    public function testARecordedWaitCountsDownAcrossTheWholeHost(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(), $clock);

        $throttle->record('https://www.reddit.com/r/PHP/.rss', 60);
        $clock->sleep(15);

        self::assertSame(45, $throttle->remainingSeconds('https://reddit.com/r/PHP/comments/1/x/.rss'));
    }

    public function testTheWaitExpires(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(), $clock);

        $throttle->record('https://www.reddit.com/', 60);
        $clock->sleep(61);

        self::assertSame(0, $throttle->remainingSeconds('https://www.reddit.com/'));
    }

    public function testOtherHostsAreUnaffected(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        $throttle->record('https://www.reddit.com/', 60);

        self::assertSame(0, $throttle->remainingSeconds('https://example.com/feed'));
    }

    public function testAZeroWaitIsClampedToTheFloorSoNothingIsForgotten(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        $recorded = $throttle->record('https://www.reddit.com/', 0);

        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $recorded);
        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $throttle->remainingSeconds('https://www.reddit.com/'));
    }

    public function testAHugeWaitIsClampedToTheCeiling(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        $recorded = $throttle->record('https://www.reddit.com/', 99_999_999);

        self::assertSame(HostThrottle::MAXIMUM_WAIT_SECONDS, $recorded);
        self::assertSame(HostThrottle::MAXIMUM_WAIT_SECONDS, $throttle->remainingSeconds('https://www.reddit.com/'));
    }
}
