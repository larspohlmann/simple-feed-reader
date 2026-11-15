<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WindowLinkCardModelTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function cardsNamingNoStory(): iterable
    {
        yield 'a root URL' => ['https://example.org', 'Storm floods the harbour', 'Example'];
        yield 'a root URL with a slash' => ['https://example.org/', 'Storm floods the harbour', 'Example'];
        yield 'a root URL with a query' => ['https://example.org/?ref=feed', 'Storm floods the harbour', 'Example'];
        yield 'a profile handle' => ['https://social.example/status/1', 'Jo Doe (@jodoe) on Network', 'Network'];
        yield 'a fediverse handle' => ['https://social.example/@jodoe', 'Jo Doe (@jodoe@instance.example)', 'Social'];
        yield 'a handle starting with an underscore' => ['https://social.example/p/1', 'Post by @_jo', 'Social'];
        yield 'the site name' => ['https://example.org/news/1', 'Example News', 'example news'];
        yield 'the host' => ['https://example.org/news/1', 'example.org', 'Some Site'];
        yield 'the first label' => ['https://www.example.com/watch?v=1', '- Example', 'Elsewhere'];
    }

    #[DataProvider('cardsNamingNoStory')]
    public function testACardShapedAsASitePersonOrSiteNameNamesNoStory(string $url, string $title, string $site): void
    {
        self::assertFalse((new WindowLinkCardModel($url, $title, null, $site))->namesAStory());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function cardsNamingAStory(): iterable
    {
        yield 'an article' => ['https://example.org/2031/storm', 'Storm floods the harbour', 'Example'];
        yield 'a one-segment path' => ['https://short.example/AbC12', 'Storm floods the harbour', 'Short'];
        yield 'a title containing the label' => ['https://example.com/rocket', 'Example launches a rocket', 'Example'];
        yield 'an at sign followed by a space' => ['https://example.org/deal', 'Deal @ 5 percent', 'Example'];
        yield 'a title with no letters' => ['https://example.org/chart', '—', '—'];
    }

    #[DataProvider('cardsNamingAStory')]
    public function testAnArticleCardNamesAStory(string $url, string $title, string $site): void
    {
        self::assertTrue((new WindowLinkCardModel($url, $title, null, $site))->namesAStory());
    }

    public function testTheSiteIsTheCardUrlsHost(): void
    {
        self::assertSame(
            'example.org',
            (new WindowLinkCardModel('https://www.example.org/storm', 'Storm', null, 'Example'))->site(),
        );
    }
}
