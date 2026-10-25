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
    }

    #[DataProvider('clocks')]
    public function testReadsAClockAsWholeSeconds(string $clock, ?int $expected): void
    {
        self::assertSame($expected, ChapterClock::seconds($clock));
    }
}
