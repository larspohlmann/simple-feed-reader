<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\UrlOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlOriginTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'path, query and fragment dropped' => ['https://example.org/a/b?c=d#e', 'https://example.org'];
        yield 'port kept' => ['http://example.org:8080/a', 'http://example.org:8080'];
        yield 'bare host' => ['https://example.org', 'https://example.org'];
        yield 'site-relative' => ['/a/b', null];
        yield 'protocol-relative' => ['//example.org/a', null];
        yield 'scheme without a host' => ['mailto:someone@example.org', null];
        yield 'unparseable' => ['http:///example.org', null];
    }

    #[DataProvider('urls')]
    public function testOfIsTheSchemeHostAndPort(string $url, ?string $expected): void
    {
        self::assertSame($expected, UrlOrigin::of($url));
    }

    public function testFromPartsAddsThePortOnlyWhenThereIsOne(): void
    {
        self::assertSame('https://example.org', UrlOrigin::fromParts('https', 'example.org', null));
        self::assertSame('https://example.org:8443', UrlOrigin::fromParts('https', 'example.org', 8443));
    }
}
