<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\ShareLinkFeed\ApplePodcastShowFeed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplePodcastShowFeedTest extends TestCase
{
    private const string SHOW_LINK = 'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894';

    /** Apple's lookup answer on 2026-10-08, trimmed to the fields a resolver may read. */
    private const string LOOKUP_BODY = /** @lang JSON */ <<<'JSON'
        {"resultCount":1,"results":[{"wrapperType":"track","kind":"podcast","collectionId":1092957894,
        "collectionName":"Lage der Nation - der Politik-Podcast aus Berlin",
        "feedUrl":"https://feeds.lagedernation.org/feeds/ldn-mp3.xml",
        "artworkUrl600":"https://is1-ssl.mzstatic.com/image/thumb/x/600x600bb.jpg"}]}
        JSON;

    public function testResolvesAShowLinkToTheFeedTheLookupNames(): void
    {
        $fetcher = $this->fetcherReturningBody('1092957894', self::LOOKUP_BODY);

        self::assertSame(
            'https://feeds.lagedernation.org/feeds/ldn-mp3.xml',
            (new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK),
        );
    }

    public function testQueriesTheLookupApiForThatShowId(): void
    {
        $fetcher = $this->fetcherResolving('1092957894', 'https://feeds.example.org/show.xml');

        (new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK);

        self::assertSame(['https://itunes.apple.com/lookup?id=1092957894'], $fetcher->fetchedUrls);
    }

    #[DataProvider('showLinks')]
    public function testResolvesEveryShapeOfShowLink(string $enteredUrl, string $showId): void
    {
        $fetcher = $this->fetcherResolving($showId, 'https://feeds.example.org/show.xml');

        self::assertSame(
            'https://feeds.example.org/show.xml',
            (new ApplePodcastShowFeed($fetcher))->feedUrl($enteredUrl),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function showLinks(): iterable
    {
        yield 'storefront and slug' => [self::SHOW_LINK, '1092957894'];
        yield 'storefront without a slug' => ['https://podcasts.apple.com/de/podcast/id1092957894', '1092957894'];
        yield 'no storefront' => ['https://podcasts.apple.com/podcast/lage-der-nation/id1092957894', '1092957894'];
        yield 'the itunes host' => ['https://itunes.apple.com/us/podcast/lage-der-nation/id1092957894', '1092957894'];
        yield 'a mixed-case host' => [
            'https://Podcasts.Apple.com/de/podcast/lage-der-nation/id1092957894',
            '1092957894',
        ];
        yield 'a trailing slash' => [
            'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894/',
            '1092957894',
        ];
        yield 'an episode link resolves to its show' => [
            'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894?i=1000700000001',
            '1092957894',
        ];
        yield 'the share link with its tracking query' => [
            'https://podcasts.apple.com/us/podcast/lage-der-nation/id1092957894?uo=4&l=en-GB',
            '1092957894',
        ];
        yield 'an uppercase path' => ['https://podcasts.apple.com/DE/Podcast/slug/id1092957894', '1092957894'];
        yield 'a different show' => ['https://podcasts.apple.com/gb/podcast/the-rest-is-x/id1611374685', '1611374685'];
    }

    #[DataProvider('nonShowLinks')]
    public function testLeavesEverythingElseAloneWithoutAskingTheApi(string $enteredUrl): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl($enteredUrl));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    #[DataProvider('resolvableFeedUrls')]
    public function testSubscribesAnyAbsoluteHttpFeedTheLookupNames(string $feedUrl): void
    {
        $fetcher = $this->fetcherResolving('1092957894', $feedUrl);

        self::assertSame($feedUrl, (new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK));
    }

    /** @return iterable<string, array{string}> */
    public static function resolvableFeedUrls(): iterable
    {
        yield 'a plain http feed' => ['http://feeds.example.org/show.xml'];
        yield 'an uppercase https scheme' => ['HTTPS://feeds.example.org/show.xml'];
    }

    /** @return iterable<string, array{string}> */
    public static function nonShowLinks(): iterable
    {
        yield 'an App Store link on the itunes host' => ['https://itunes.apple.com/de/app/podcasts/id525463029'];
        yield 'an App Store link on its own host' => ['https://apps.apple.com/de/app/podcasts/id525463029'];
        yield 'an id with letters' => ['https://podcasts.apple.com/de/podcast/lage-der-nation/id12ab'];
        yield 'a path without an id' => ['https://podcasts.apple.com/de/podcast/lage-der-nation'];
        yield 'an Apple page that is not a show' => ['https://podcasts.apple.com/de/browse'];
        yield 'a look-alike host' => ['https://podcasts.apple.com.evil.example/de/podcast/x/id1092957894'];
        yield 'a non-Apple host' => ['https://example.com/de/podcast/x/id1092957894'];
        yield 'a feed URL' => ['https://feeds.lagedernation.org/feeds/ldn-mp3.xml'];
        yield 'a segment before the storefront' => ['https://podcasts.apple.com/x/de/podcast/slug/id1092957894'];
        yield 'a storefront that is not two letters' => ['https://podcasts.apple.com/usa/podcast/slug/id1092957894'];
        yield 'text that is not a URL' => ['not a url'];
        yield 'an Apple host without a path' => ['https://podcasts.apple.com'];
    }

    #[DataProvider('unresolvableLookups')]
    public function testFallsThroughWhenTheLookupNamesNoPodcastFeed(string $body): void
    {
        $fetcher = $this->fetcherReturningBody('1092957894', $body);

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK));
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvableLookups(): iterable
    {
        yield 'an unknown id' => ['{"resultCount":0,"results":[]}'];
        yield 'an App Store id' => ['{"resultCount":1,"results":[{"kind":"software","trackName":"Podcasts"}]}'];
        yield 'a podcast without a feed' => ['{"resultCount":1,"results":[{"kind":"podcast"}]}'];
        yield 'a feed that is not a string' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":42}]}'];
        yield 'an empty feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":""}]}'];
        yield 'a relative feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"/feed.xml"}]}'];
        yield 'a software result that names a feed' => [
            '{"resultCount":1,"results":[{"kind":"software","feedUrl":"https://feeds.example.org/show.xml"}]}',
        ];
        yield 'a result without a kind that names a feed' => [
            '{"resultCount":1,"results":[{"feedUrl":"https://feeds.example.org/show.xml"}]}',
        ];
        yield 'an http scheme without a host' => [
            '{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"http:feed.xml"}]}',
        ];
        yield 'a javascript feed' => [
            '{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"javascript:alert(1)"}]}',
        ];
        yield 'an ftp feed' => ['{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"ftp://x.example/feed"}]}'];
        yield 'results that are not a list' => ['{"resultCount":1,"results":"https://x.example/feed"}'];
        yield 'the body is not JSON at all' => [/** @lang TEXT */ '<!doctype html><html><body>Lookup</body></html>'];
        yield 'the body is a bare JSON scalar' => ['"1092957894"'];
    }

    public function testFallsThroughWhenTheLookupApiCannotBeReached(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(
            'https://itunes.apple.com/lookup?id=1092957894',
            new FeedUnreachableException('x: HTTP 503', 503),
        );

        self::assertNull((new ApplePodcastShowFeed($fetcher))->feedUrl(self::SHOW_LINK));
    }

    private function fetcherResolving(string $showId, string $feedUrl): StubFeedFetcher
    {
        return $this->fetcherReturningBody(
            $showId,
            (string) json_encode(
                ['resultCount' => 1, 'results' => [['kind' => 'podcast', 'feedUrl' => $feedUrl]]],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function fetcherReturningBody(string $showId, string $body): StubFeedFetcher
    {
        $apiUrl = sprintf('https://itunes.apple.com/lookup?id=%s', $showId);
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn($apiUrl, FetchResponseModel::fetched(
            $apiUrl,
            permanentRedirect: false,
            body: $body,
            etag: null,
            lastModified: null,
        ));

        return $fetcher;
    }
}
