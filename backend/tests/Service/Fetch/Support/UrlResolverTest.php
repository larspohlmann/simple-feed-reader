<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Support\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    private const string ARTICLE = 'https://example.com/news/2026/article.html?id=7';

    /** @return iterable<string, array{string, string, string}> */
    public static function references(): iterable
    {
        yield 'absolute stays' => ['https://example.org/a/b', 'https://other.example/x', 'https://other.example/x'];
        yield 'protocol-relative' => ['https://example.org/a/b', '//cdn.example/x', 'https://cdn.example/x'];
        yield 'site-relative keeps the port' => ['http://example.org:8080/a/b', '/x', 'http://example.org:8080/x'];
        yield 'path-relative keeps the directory' => ['https://example.org/a/b', 'x', 'https://example.org/a/x'];
        yield 'query-only keeps the path' => [
            self::ARTICLE,
            '?page=2',
            'https://example.com/news/2026/article.html?page=2',
        ];
        yield 'fragment keeps path and query' => [
            self::ARTICLE,
            '#comments',
            'https://example.com/news/2026/article.html?id=7#comments',
        ];
        yield 'dot-dot climbs a directory' => [self::ARTICLE, '../img/a.jpg', 'https://example.com/news/img/a.jpg'];
        yield 'dot stays in the directory' => [self::ARTICLE, './b.jpg', 'https://example.com/news/2026/b.jpg'];
        yield 'empty is the base itself' => [self::ARTICLE, '', self::ARTICLE];
    }

    #[DataProvider('references')]
    public function testResolvesAReferenceAgainstItsBase(string $base, string $reference, string $expected): void
    {
        self::assertSame($expected, UrlResolver::resolve($base, $reference));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unresolvable(): iterable
    {
        yield 'base without a host' => ['/a/b', 'x'];
        yield 'base that does not parse' => ['https://[bad', 'x'];
        yield 'mailto reference' => [self::ARTICLE, 'mailto:a@example.org'];
        yield 'javascript reference' => [self::ARTICLE, 'javascript:void(0)'];
    }

    #[DataProvider('unresolvable')]
    public function testAReferenceWithoutAnHttpResultCannotResolve(string $base, string $reference): void
    {
        $this->expectException(FeedUnreachableException::class);

        UrlResolver::resolve($base, $reference);
    }
}
