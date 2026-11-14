<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Support\EnteredUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnteredUrlTest extends TestCase
{
    #[DataProvider('entered')]
    public function testWithScheme(string $entered, string $expected): void
    {
        self::assertSame($expected, EnteredUrl::withScheme($entered));
    }

    /** @return iterable<string, array{string, string}> */
    public static function entered(): iterable
    {
        yield 'a bare host' => ['example.com', 'https://example.com'];
        yield 'padding is trimmed' => ['  example.com/feed  ', 'https://example.com/feed'];
        yield 'an https address is kept' => ['https://example.com', 'https://example.com'];
        yield 'an http address is kept' => ['http://example.com', 'http://example.com'];
        yield 'another scheme is kept' => ['ftp://example.com', 'ftp://example.com'];
        yield 'a scheme in capitals is kept' => ['HTTPS://example.com', 'HTTPS://example.com'];
        yield 'a protocol-relative address' => ['//example.com', 'https://example.com'];
        yield 'a scheme later in the address is not the address scheme' => [
            'example.com/go?to=http://other.example',
            'https://example.com/go?to=http://other.example',
        ];
        yield 'nothing stays nothing' => ['   ', ''];
    }
}
