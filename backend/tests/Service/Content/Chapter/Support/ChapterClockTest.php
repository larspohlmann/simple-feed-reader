<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Service\Chapter\Support\ChapterClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChapterClockTest extends TestCase
{
    /** @return iterable<string, array{string, int|null}> */
    public static function clocks(): iterable
    {
        yield 'seconds' => ['45', 45];
        yield 'minutes' => ['4:16', 256];
        yield 'zero' => ['0:00', 0];
        yield 'hours' => ['1:02:03', 3723];
        yield 'podlove with millis' => ['00:10:51.500', 651];
        yield 'surrounding space' => [' 2:00 ', 120];
        yield 'empty' => ['', null];
        yield 'letters' => ['4:1a', null];
        yield 'four parts' => ['1:2:3:4', null];
        yield 'seconds past 59' => ['4:61', null];
        yield 'negative' => ['-1:00', null];
        yield 'bare seconds above 59' => ['90', 90];
        yield 'minutes at 59' => ['0:59', 59];
        yield 'hours minutes seconds at 59' => ['1:59:59', 7199];
        yield 'fraction floored' => ['0:10.9', 10];
        yield 'fraction on a minute at 59' => ['0:59.9', 59];
        yield 'bare seconds fraction floored' => ['45.5', 45];
        yield 'overflowing hours' => ['9999999999999999:00:00', null];
    }

    #[DataProvider('clocks')]
    public function testReadsAClockAsWholeSeconds(string $clock, ?int $expected): void
    {
        self::assertSame($expected, ChapterClock::seconds($clock));
    }
}
