<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\ShareLinkFeed;

use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Tests\Service\Discovery\BuildsFeedDiscovery;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves FeedDiscovery consults ApplePodcastShowFeed, then fetches and parses what it resolves; the resolver's own
 * edge cases are ApplePodcastShowFeedTest's.
 */
final class ApplePodcastShowDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    private const string SHOW_LINK =
        'https://podcasts.apple.com/de/podcast/lage-der-nation/id1092957894?i=1000700000001';

    private const string LOOKUP_URL = 'https://itunes.apple.com/lookup?id=1092957894';

    /** The feed lives on the publisher's host, which nothing about the Apple link names; the episode query is dropped. */
    public function testAShowLinkSubscribesTheFeedTheLookupResolves(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning(
            'https://feeds.example.org/show.xml',
            'https://feeds.example.org/show.xml',
            $xml,
        );
        $this->stubLookup(
            $fetcher,
            '{"resultCount":1,"results":[{"kind":"podcast","feedUrl":"https://feeds.example.org/show.xml"}]}',
        );

        $result = $this->discovery($fetcher)->discover(self::SHOW_LINK, ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://feeds.example.org/show.xml', $result->feed->url);
        self::assertSame([], $result->candidates);
        self::assertNull($result->scrapeFailureReason);
    }

    /** An id Apple does not know must not fabricate a subscription: discovery falls through to the ordinary path. */
    public function testAnUnresolvableShowLinkFallsThroughInsteadOfSubscribing(): void
    {
        $fetcher = $this->fetcher();
        $this->stubLookup($fetcher, '{"resultCount":0,"results":[]}');

        $result = $this->discovery($fetcher)->discover(self::SHOW_LINK, ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertSame([], $result->candidates);
        self::assertContains(self::SHOW_LINK, $fetcher->fetchedUrls);
    }

    private function stubLookup(StubFeedFetcher $fetcher, string $body): void
    {
        $fetcher->willReturn(
            self::LOOKUP_URL,
            FetchResponseModel::fetched(
                self::LOOKUP_URL,
                permanentRedirect: false,
                body: $body,
                etag: null,
                lastModified: null,
            ),
        );
    }
}
