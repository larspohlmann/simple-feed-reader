<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\HostThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class HostThrottleTest extends TestCase
{
    private const string REDDIT = 'https://www.reddit.com/';

    private MockClock $clock;
    private ArrayAdapter $cache;
    private HostThrottle $throttle;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-24 12:00:00');
        $this->cache = new ArrayAdapter(clock: $this->clock);
        $this->throttle = new HostThrottle($this->cache, $this->clock);
    }

    public function testAFreshHostIsFree(): void
    {
        self::assertSame(0, $this->throttle->remainingSeconds('https://www.reddit.com/r/PHP/.rss'));
    }

    public function testARecordedWaitCountsDownAcrossTheWholeHost(): void
    {
        $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 60);
        $this->clock->sleep(15);

        self::assertSame(45, $this->throttle->remainingSeconds('https://reddit.com/r/PHP/comments/1/x/.rss'));
    }

    public function testTheWaitExpires(): void
    {
        $this->throttle->record(self::REDDIT, 60);
        $this->clock->sleep(61);

        self::assertSame(0, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testAnEntryTheCacheHasNotYetEvictedReadsAsFree(): void
    {
        $lateCache = new ArrayAdapter(clock: new MockClock('2026-09-24 12:00:00'));
        $throttle = new HostThrottle($lateCache, $this->clock);

        $throttle->record(self::REDDIT, 60);
        $this->clock->sleep(61);

        self::assertSame(0, $throttle->remainingSeconds(self::REDDIT));
    }

    public function testOtherHostsAreUnaffected(): void
    {
        $this->throttle->record(self::REDDIT, 60);

        self::assertSame(0, $this->throttle->remainingSeconds('https://example.com/feed'));
    }

    public function testAZeroWaitIsClampedToTheFloorSoNothingIsForgotten(): void
    {
        $recorded = $this->throttle->record(self::REDDIT, 0);

        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $recorded);
        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testAnUnnamedWaitIsTheFloor(): void
    {
        $recorded = $this->throttle->record(self::REDDIT, null);

        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $recorded);
        self::assertSame(HostThrottle::MINIMUM_WAIT_SECONDS, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testAHugeWaitIsClampedToTheCeiling(): void
    {
        $recorded = $this->throttle->record(self::REDDIT, 99_999_999);

        self::assertSame(HostThrottle::MAXIMUM_WAIT_SECONDS, $recorded);
        self::assertSame(HostThrottle::MAXIMUM_WAIT_SECONDS, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testAShorterWaitNeverCutsALongerOneShort(): void
    {
        $this->throttle->record(self::REDDIT, 600);
        $recorded = $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 60);

        self::assertSame(600, $recorded);
        self::assertSame(600, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testALongerWaitExtendsAShorterOne(): void
    {
        $this->throttle->record(self::REDDIT, 60);
        $recorded = $this->throttle->record(self::REDDIT, 600);

        self::assertSame(600, $recorded);
        self::assertSame(600, $this->throttle->remainingSeconds(self::REDDIT));
    }

    public function testTheCacheEntryExpiresWithTheWait(): void
    {
        $this->throttle->record(self::REDDIT, 60);
        $key = (string) array_key_first($this->cache->getValues());

        $this->clock->sleep(59);
        self::assertTrue($this->cache->hasItem($key));
        $this->clock->sleep(2);
        self::assertFalse($this->cache->hasItem($key));
    }
}
