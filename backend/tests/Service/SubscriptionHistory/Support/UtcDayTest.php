<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Support;

use App\Service\SubscriptionHistory\Support\UtcDay;
use PHPUnit\Framework\TestCase;

final class UtcDayTest extends TestCase
{
    public function testTheDayIsTheStoredWallClockDateWhateverTheDefaultZone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            self::assertSame('2026-10-24', UtcDay::of(new \DateTimeImmutable('2026-10-24 23:30:00')));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testStartIsMidnightOfThatDay(): void
    {
        self::assertSame('2026-10-24 00:00:00', UtcDay::start('2026-10-24')->format('Y-m-d H:i:s'));
    }

    public function testShiftCrossesMonthEnds(): void
    {
        self::assertSame('2026-09-30', UtcDay::shift('2026-10-01', -1));
        self::assertSame('2026-11-01', UtcDay::shift('2026-10-31', 1));
    }
}
