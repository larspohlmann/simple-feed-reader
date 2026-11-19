<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky\Support;

use App\Service\Social\Bluesky\Support\BlueskyTid;
use PHPUnit\Framework\TestCase;

final class BlueskyTidTest extends TestCase
{
    public function testEncodesAKnownMoment(): void
    {
        self::assertSame('3n2iyl6bw2222', BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:00:00.000000Z'), 0));
    }

    public function testCarriesTheClockIdInItsLastTenBits(): void
    {
        $moment = new \DateTimeImmutable('2026-11-17T12:00:00.000000Z');

        self::assertSame('3n2iyl6bw2223', BlueskyTid::at($moment, 1));
        self::assertSame('3n2iyl6bw22zz', BlueskyTid::at($moment, 1023));
        self::assertSame('3n2iyl6bw2223', BlueskyTid::at($moment, 1025));
    }

    public function testCountsMicroseconds(): void
    {
        self::assertSame('3n2iyl6bw2322', BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:00:00.000001Z'), 0));
    }

    public function testIsThirteenSortableCharacters(): void
    {
        $tid = BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:34:56.789012Z'), 517);

        self::assertMatchesRegularExpression('/^[234567a-z]{13}$/', $tid);
        self::assertStringContainsString($tid[0], '234567abcdefghij');
    }

    public function testLaterMomentsSortAfter(): void
    {
        $earlier = BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:00:00.000000Z'), 1023);
        $later = BlueskyTid::at(new \DateTimeImmutable('2026-11-17T12:00:00.000001Z'), 0);

        self::assertLessThan(0, strcmp($earlier, $later));
    }
}
