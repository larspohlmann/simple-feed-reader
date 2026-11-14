<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\Media\Support;

use App\Service\Content\Reader\Media\Support\ClipDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClipDurationTest extends TestCase
{
    public function testWritesWholeSecondsAsAnIsoDuration(): void
    {
        self::assertSame('PT2871S', ClipDuration::toIso(2871));
    }

    public function testReadsBackTheIsoDurationItWrites(): void
    {
        self::assertSame(2871, ClipDuration::fromIso('PT2871S'));
    }

    /** @return iterable<string, array{?string}> */
    public static function unreadableDurations(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['PT0S'];
        yield 'minutes' => ['PT5M'];
        yield 'fractional seconds' => ['PT5.5S'];
        yield 'leading text' => ['xPT5S'];
        yield 'trailing text' => ['PT5Sx'];
        yield 'bare digits' => ['300'];
        yield 'trailing newline' => ["PT5S\n"];
    }

    #[DataProvider('unreadableDurations')]
    public function testReadsNothingFromAnythingButWholeSeconds(?string $iso): void
    {
        self::assertNull(ClipDuration::fromIso($iso));
    }

    /** @return iterable<string, array{int, string}> */
    public static function clocks(): iterable
    {
        yield 'under a minute' => [47, '0:47'];
        yield 'padded seconds' => [127, '2:07'];
        yield 'just under an hour' => [3599, '59:59'];
        yield 'one hour' => [3600, '1:00:00'];
        yield 'hour plus' => [3723, '1:02:03'];
        yield 'clip of 2871 seconds' => [2871, '47:51'];
    }

    #[DataProvider('clocks')]
    public function testShowsTheClockTheClientShows(int $seconds, string $clock): void
    {
        self::assertSame($clock, ClipDuration::clock($seconds));
    }
}
