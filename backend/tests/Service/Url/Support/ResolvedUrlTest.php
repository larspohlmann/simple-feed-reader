<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\ResolvedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolvedUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function references(): iterable
    {
        yield 'absolute' => ['https://cdn.test/a.jpg', 'https://cdn.test/a.jpg'];
        yield 'foreign scheme' => ['javascript:alert(1)', 'javascript:alert(1)'];
        yield 'scheme-relative' => ['//cdn.test/a.jpg', 'https://cdn.test/a.jpg'];
        yield 'root-relative' => ['/img/a.jpg', 'https://site.test:8443/img/a.jpg'];
        yield 'path-relative' => ['a.jpg', 'https://site.test:8443/news/a.jpg'];
        yield 'blank' => ['', ''];
    }

    #[DataProvider('references')]
    public function testResolvesAgainstThePage(string $reference, string $expected): void
    {
        self::assertSame($expected, ResolvedUrl::against('https://site.test:8443/news/story?x=1', $reference));
    }

    public function testPathRelativeAgainstARootPage(): void
    {
        self::assertSame('https://site.test/a.jpg', ResolvedUrl::against('https://site.test', 'a.jpg'));
    }
}
