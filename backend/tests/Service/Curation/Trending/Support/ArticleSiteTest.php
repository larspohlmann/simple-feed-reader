<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\ArticleSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArticleSiteTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function urls(): iterable
    {
        yield 'a host' => ['https://news.example.org/2031/storm', 'news.example.org'];
        yield 'a leading www. dropped' => ['https://www.example.com/storm', 'example.com'];
        yield 'lower-cased' => ['https://WWW.Example.COM/storm', 'example.com'];
        yield 'www. inside the host kept' => ['https://news.www.example.com/storm', 'news.www.example.com'];
        yield 'no URL' => [null, null];
        yield 'no host' => ['/storm/only-a-path', null];
        yield 'unparsable' => ['https://:80', null];
    }

    #[DataProvider('urls')]
    public function testTheSiteIsTheHostWithoutALeadingWww(?string $url, ?string $site): void
    {
        self::assertSame($site, ArticleSite::fromUrl($url));
    }
}
