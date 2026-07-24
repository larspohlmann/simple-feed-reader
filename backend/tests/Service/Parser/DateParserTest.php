<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\DateParser;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    public function testNormalisesAnOffsetDateToUtc(): void
    {
        // The offset must be folded into UTC, not dropped: 17:51:45 +02:00 = 15:51:45Z.
        // Otherwise the stored naive wall-clock lands ~2h ahead of the UTC clock the
        // rest of the app uses, and such entries render as "now" (#48).
        $d = DateParser::parse('Fri, 24 Jul 2026 17:51:45 +0200');

        self::assertNotNull($d);
        self::assertSame('2026-07-24T15:51:45+00:00', $d->format(\DateTimeInterface::ATOM));
    }

    public function testKeepsAnAlreadyUtcDate(): void
    {
        $d = DateParser::parse('2026-07-24T15:51:45Z');

        self::assertNotNull($d);
        self::assertSame('2026-07-24T15:51:45+00:00', $d->format(\DateTimeInterface::ATOM));
    }

    public function testTreatsAnOffsetlessDateAsUtc(): void
    {
        $d = DateParser::parse('2026-07-24 12:00:00');

        self::assertNotNull($d);
        self::assertSame('2026-07-24T12:00:00+00:00', $d->format(\DateTimeInterface::ATOM));
    }

    public function testReturnsNullForEmptyOrUnparsableInput(): void
    {
        self::assertNull(DateParser::parse(null));
        self::assertNull(DateParser::parse('   '));
        self::assertNull(DateParser::parse('not a date'));
    }
}
