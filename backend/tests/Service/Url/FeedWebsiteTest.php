<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\FeedWebsite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every case here is a feed from a real library, named in the case label. The
 * rule exists because those feeds broke it, so the fixtures stay verbatim.
 */
final class FeedWebsiteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null, string|null}>
     */
    public static function feeds(): iterable
    {
        yield 'a published link is used as it stands' => [
            'https://www.theguardian.com/international/rss',
            'https://www.theguardian.com/international',
            'https://www.theguardian.com/international',
        ];

        yield 'no published link falls back to the feed origin' => [
            'https://www.aljazeera.com/xml/rss/all.xml',
            null,
            'https://www.aljazeera.com',
        ];

        yield 'Telepolis points its link at the feed document' => [
            'https://www.telepolis.de/feed.xml',
            'https://www.telepolis.de/feed.xml',
            'https://www.telepolis.de',
        ];

        yield 'Politico points at a feed document on a feed host' => [
            'https://rss.politico.com/politics-news.xml',
            'https://rss.politico.com/politics-news.xml',
            'https://politico.com',
        ];

        yield 'Nature points at a feeds. host that serves no site' => [
            'https://www.nature.com/nature.rss',
            'http://feeds.nature.com/nature/rss/current',
            'https://www.nature.com',
        ];

        yield 'ZDFheute leaked an internal service name' => [
            'https://www.zdfheute.de/rss/zdf/nachrichten',
            'https://ssi-proxy-backends.default.svc.futura-prod/rss/zdf/nachrichten',
            'https://www.zdfheute.de',
        ];

        yield 'CBC keeps a link that only carries an rss query parameter' => [
            'https://www.cbc.ca/webfeed/rss/rss-topstories',
            'https://www.cbc.ca/news/?cmp=rss',
            'https://www.cbc.ca/news/?cmp=rss',
        ];

        yield 'Angular keeps a link whose rss marker is in the query' => [
            'https://blog.angular.dev/feed',
            'https://blog.angular.dev?source=rss----447683c3d9a3---4',
            'https://blog.angular.dev?source=rss----447683c3d9a3---4',
        ];

        yield 'a punycode domain is public' => [
            'https://feeds.example.com/feed.xml',
            'https://example.xn--p1ai/',
            'https://example.xn--p1ai/',
        ];

        yield 'a single-label host is not somewhere to send a reader' => [
            'https://www.example.com/feed.xml',
            'http://localhost/',
            'https://www.example.com',
        ];

        yield 'a bare address is not somewhere to send a reader' => [
            'https://www.example.com/feed.xml',
            'http://192.168.1.10/',
            'https://www.example.com',
        ];

        yield 'the Symfony blog is syndicated, so its feed host names no website' => [
            'https://feeds.feedburner.com/symfony/blog',
            null,
            null,
        ];

        yield 'a syndicated feed still uses a published link when it has one' => [
            'https://feeds.feedburner.com/symfony/blog',
            'https://symfony.com/blog/',
            'https://symfony.com/blog/',
        ];

        yield 'a port survives the fallback' => [
            'http://news.example.com:8080/feed.xml',
            null,
            'http://news.example.com:8080',
        ];

        yield 'an unparseable feed address yields nothing rather than a guess' => [
            'not a url',
            null,
            null,
        ];
    }

    #[DataProvider('feeds')]
    public function testResolvesTheWebsiteAFeedBelongsTo(
        string $feedUrl,
        ?string $publishedLink,
        ?string $expected,
    ): void {
        self::assertSame($expected, FeedWebsite::of($feedUrl, $publishedLink));
    }
}
