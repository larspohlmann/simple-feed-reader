<?php

declare(strict_types=1);

namespace App\Tests\Service\Clock;

use App\Service\Clock\DatabaseClock;
use App\Tests\DbTestCase;

final class DatabaseClockTest extends DbTestCase
{
    public function testNowReadsTheDatabaseClockInUtc(): void
    {
        $clock = new DatabaseClock($this->em->getConnection());

        $before = time();
        $now = $clock->now();
        $after = time();

        self::assertSame('UTC', $now->getTimezone()->getName());
        // The DB clock tracks the test host's clock; allow slop for the round trip.
        self::assertGreaterThanOrEqual($before - 2, $now->getTimestamp());
        self::assertLessThanOrEqual($after + 2, $now->getTimestamp());
    }

    public function testWithTimeZoneShiftsTheZoneNotTheInstant(): void
    {
        $utcClock = new DatabaseClock($this->em->getConnection());
        $berlinClock = $utcClock->withTimeZone('Europe/Berlin');

        $utc = $utcClock->now();
        $berlin = $berlinClock->now();

        self::assertSame('Europe/Berlin', $berlin->getTimezone()->getName());
        // Same instant, read moments apart: the wall-clock offset holds regardless.
        self::assertLessThan(5, abs($berlin->getTimestamp() - $utc->getTimestamp()));
    }
}
