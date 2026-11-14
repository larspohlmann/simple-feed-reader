<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\UrlOrigin;
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

    /** @return iterable<string, array{string, ?string}> */
    public static function canonicalUrls(): iterable
    {
        yield 'scheme and host lower-cased' => ['HTTPS://Example.ORG/A', 'https://example.org'];
        yield 'default https port dropped' => ['https://example.org:443/a', 'https://example.org'];
        yield 'default http port dropped' => ['http://example.org:80/a', 'http://example.org'];
        yield 'another scheme\'s default port kept' => ['http://example.org:443/a', 'http://example.org:443'];
        yield 'other port kept' => ['https://example.org:8443/a', 'https://example.org:8443'];
        yield 'unknown scheme keeps its port' => ['ftp://example.org:21/a', 'ftp://example.org:21'];
        yield 'no host' => ['/a/b', null];
    }

    #[DataProvider('canonicalUrls')]
    public function testCanonicalIsTheLowerCasedOriginWithoutADefaultPort(string $url, ?string $expected): void
    {
        self::assertSame($expected, UrlOrigin::canonical($url));
    }

    public function testOnlyTheSchemesOwnPortIsItsDefault(): void
    {
        self::assertTrue(UrlOrigin::isDefaultPort('https', 443));
        self::assertTrue(UrlOrigin::isDefaultPort('http', 80));
        self::assertFalse(UrlOrigin::isDefaultPort('https', 80));
        self::assertFalse(UrlOrigin::isDefaultPort('https', null));
        self::assertFalse(UrlOrigin::isDefaultPort('ftp', 21));
        self::assertFalse(UrlOrigin::isDefaultPort('ftp', null));
    }
}
