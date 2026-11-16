<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification\Support;

use App\Service\Social\Notification\Support\NetworkTimestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NetworkTimestampTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function readable(): iterable
    {
        yield 'an offset is converted' => ['2026-11-16T10:00:00+02:00', '2026-11-16 08:00:00'];
        yield 'a Z suffix is kept' => ['2026-11-16T08:00:00.123456Z', '2026-11-16 08:00:00.123456'];
        yield 'no suffix means UTC' => ['2026-11-16T08:00:00', '2026-11-16 08:00:00'];
        yield 'a space-separated naive value means UTC' => ['2026-11-16 08:00:00.5', '2026-11-16 08:00:00.5'];
    }

    #[DataProvider('readable')]
    public function testReadsTimestampsAsUtc(string $timestamp, string $expected): void
    {
        $parsed = NetworkTimestamp::toUtc($timestamp);

        self::assertEquals(new \DateTimeImmutable($expected, new \DateTimeZone('UTC')), $parsed);
        self::assertSame('UTC', $parsed?->getTimezone()->getName());
    }

    public function testDropsWhatIsNotATimestamp(): void
    {
        self::assertNull(NetworkTimestamp::toUtc('not a time'));
        self::assertNull(NetworkTimestamp::toUtc(null));
    }
}
