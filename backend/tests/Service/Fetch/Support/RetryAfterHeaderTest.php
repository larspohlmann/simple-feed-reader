<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Support\RetryAfterHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterHeaderTest extends TestCase
{
    #[DataProvider('headers')]
    public function testOnlyADelayInSecondsNamesAWait(?string $header, ?int $seconds): void
    {
        self::assertSame($seconds, RetryAfterHeader::seconds($header));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function headers(): iterable
    {
        yield 'seconds' => ['120', 120];
        yield 'padded' => [' 30 ', 30];
        yield 'a date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null];
        yield 'negative' => ['-5', null];
        yield 'longer than any wait, left to the caller to clamp' => ['1234567890', 1234567890];
        yield 'absent' => [null, null];
    }

    #[DataProvider('datedHeaders')]
    public function testADateCountsFromTheGivenInstant(?string $header, ?int $seconds): void
    {
        $now = new \DateTimeImmutable('2026-10-21 07:27:00 UTC');

        self::assertSame($seconds, RetryAfterHeader::secondsFrom($header, $now));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function datedHeaders(): iterable
    {
        yield 'seconds' => ['45', 45];
        yield 'a future date' => ['Wed, 21 Oct 2026 07:28:00 GMT', 60];
        yield 'a past date' => ['Wed, 21 Oct 2026 07:26:00 GMT', null];
        yield 'a date of this very instant' => ['Wed, 21 Oct 2026 07:27:00 GMT', null];
        yield 'a date padded with whitespace' => [' Wed, 21 Oct 2026 07:28:00 GMT ', 60];
        yield 'nonsense' => ['soon', null];
        yield 'absent' => [null, null];
    }
}
