<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Support\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function references(): iterable
    {
        yield 'absolute stays' => ['https://example.org/a/b', 'https://other.example/x', 'https://other.example/x'];
        yield 'protocol-relative' => ['https://example.org/a/b', '//cdn.example/x', 'https://cdn.example/x'];
        yield 'site-relative keeps the port' => ['http://example.org:8080/a/b', '/x', 'http://example.org:8080/x'];
        yield 'path-relative keeps the directory' => ['https://example.org/a/b', 'x', 'https://example.org/a/x'];
    }

    #[DataProvider('references')]
    public function testResolvesAReferenceAgainstItsBase(string $base, string $reference, string $expected): void
    {
        self::assertSame($expected, UrlResolver::resolve($base, $reference));
    }

    public function testABaseWithoutAHostCannotResolveARelativeReference(): void
    {
        $this->expectException(FeedUnreachableException::class);

        UrlResolver::resolve('/a/b', 'x');
    }
}
