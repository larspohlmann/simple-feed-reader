<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\MediaDuration;
use PHPUnit\Framework\TestCase;

final class MediaDurationTest extends TestCase
{
    public function testReadsPlainSeconds(): void
    {
        self::assertSame(3600, MediaDuration::seconds('3600'));
    }

    public function testReadsHoursMinutesSeconds(): void
    {
        self::assertSame(3723, MediaDuration::seconds('1:02:03'));
    }

    public function testReadsMinutesSeconds(): void
    {
        self::assertSame(630, MediaDuration::seconds('10:30'));
    }

    public function testRejectsEmpty(): void
    {
        self::assertNull(MediaDuration::seconds(''));
        self::assertNull(MediaDuration::seconds(null));
    }

    public function testRejectsZero(): void
    {
        self::assertNull(MediaDuration::seconds('0'));
    }

    public function testRejectsNonNumericParts(): void
    {
        self::assertNull(MediaDuration::seconds('about an hour'));
        self::assertNull(MediaDuration::seconds('1:xx'));
    }
}
