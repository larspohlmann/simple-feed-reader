<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchAttempt;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\HostSlots;
use PHPUnit\Framework\TestCase;

final class HostSlotsTest extends TestCase
{
    private function attempt(string $url): FetchAttempt
    {
        return FetchAttempt::start(1, new FetchTicket($url));
    }

    public function testAFreshHostHasCapacity(): void
    {
        $slots = new HostSlots(2);

        self::assertTrue($slots->hasCapacityFor($this->attempt('https://example.com/feed')));
    }

    public function testCapacityRemainsUntilTheCapIsReached(): void
    {
        $slots = new HostSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        self::assertTrue($slots->hasCapacityFor($attempt), 'one of two slots taken');

        $slots->acquire($attempt);
        self::assertFalse($slots->hasCapacityFor($attempt), 'both slots taken');
    }

    public function testReleasingASlotRestoresCapacity(): void
    {
        $slots = new HostSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);

        self::assertTrue($slots->hasCapacityFor($attempt));
    }

    public function testASingleReleaseFreesExactlyOneSlot(): void
    {
        $slots = new HostSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);
        $slots->acquire($attempt);

        // Two acquired, one released, one re-acquired: the host is full again, so
        // the release must have freed one slot, not two.
        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testHostsAreCountedIndependently(): void
    {
        $slots = new HostSlots(1);
        $one = $this->attempt('https://one.example.com/feed');
        $two = $this->attempt('https://two.example.com/feed');

        $slots->acquire($one);

        self::assertFalse($slots->hasCapacityFor($one));
        self::assertTrue($slots->hasCapacityFor($two));
    }

    public function testNormalisedHostsShareTheSameSlots(): void
    {
        $slots = new HostSlots(1);

        $slots->acquire($this->attempt('https://www.example.com:443/feed'));

        self::assertFalse($slots->hasCapacityFor($this->attempt('https://example.com/other')));
    }

    public function testRejectsACapBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-host concurrency must be at least 1, got 0.');

        new HostSlots(0);
    }
}
