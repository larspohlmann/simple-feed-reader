<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\AbsoluteHttpUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbsoluteHttpUrlTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function candidates(): iterable
    {
        yield 'https' => ['https://example.org/a', true];
        yield 'http' => ['http://example.org/a', true];
        yield 'upper-case scheme' => ['HTTPS://example.org/a', true];
        yield 'site-relative' => ['/a/b', false];
        yield 'protocol-relative' => ['//example.org/a', false];
        yield 'foreign scheme' => ['javascript:alert(1)', false];
        yield 'scheme not at the start' => [' see https://example.org/a', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('candidates')]
    public function testMatchesOnlyAnAbsoluteHttpUrl(string $candidate, bool $expected): void
    {
        self::assertSame($expected, AbsoluteHttpUrl::matches($candidate));
    }

    public function testOrNullKeepsAnAbsoluteHttpUrl(): void
    {
        self::assertSame('https://example.org/a', AbsoluteHttpUrl::orNull('https://example.org/a'));
    }

    public function testOrNullDropsAnythingElse(): void
    {
        self::assertNull(AbsoluteHttpUrl::orNull('/a/b'));
        self::assertNull(AbsoluteHttpUrl::orNull(null));
    }
}
