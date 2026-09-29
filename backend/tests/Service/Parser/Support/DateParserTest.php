<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\DateParser;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    public function testNormalisesAnOffsetDateToUtc(): void
    {
        // Folded into UTC, not dropped: 17:51:45 +02:00 is 15:51:45Z.
        $date = DateParser::parse('Fri, 24 Jul 2026 17:51:45 +0200');

        self::assertNotNull($date);
        self::assertSame('2026-07-24T15:51:45+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testKeepsAnAlreadyUtcDate(): void
    {
        $date = DateParser::parse('2026-07-24T15:51:45Z');

        self::assertNotNull($date);
        self::assertSame('2026-07-24T15:51:45+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testTreatsAnOffsetlessDateAsUtc(): void
    {
        $date = DateParser::parse('2026-07-24 12:00:00');

        self::assertNotNull($date);
        self::assertSame('2026-07-24T12:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testReturnsNullForEmptyOrUnparsableInput(): void
    {
        self::assertNull(DateParser::parse(null));
        self::assertNull(DateParser::parse('   '));
        self::assertNull(DateParser::parse('not a date'));
    }
}
