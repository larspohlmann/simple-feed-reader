<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\FeedDiscovery;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Tests\Service\Scraper\ScrapedFixtures;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedDiscoveryTest extends KernelTestCase
{
    use ScrapedFixtures;

    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        return new FeedDiscovery($fetcher, $parser, $extractor);
    }

    private function fetcherReturning(string $url, string $finalUrl, string $body): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            $url,
            FetchResponse::fetched($finalUrl, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );

        return $fetcher;
    }

    public function testDirectFeedUrlReturnsCanonicalFinalUrl(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning('https://example.com/feed', 'https://example.com/feed.xml', $xml);

        $result = $this->discovery($fetcher)->discover('https://example.com/feed');

        self::assertTrue($result->isDirectFeed);
        self::assertSame('https://example.com/feed.xml', $result->feedUrl);
        self::assertNull($result->scrapeFailureReason);
    }

    public function testHtmlPageReturnsResolvedCandidates(): void
    {
        $html = <<<'HTML'
            <!doctype html><html><head>
              <link rel="alternate" type="application/rss+xml" title="Main" href="/rss.xml">
              <link rel="alternate" type="application/atom+xml" href="https://cdn.example.com/atom">
              <link rel="stylesheet" href="/style.css">
            </head><body>Hello</body></html>
            HTML;

        $fetcher = $this->fetcherReturning('https://example.com/blog', 'https://example.com/blog/', $html);

        $result = $this->discovery($fetcher)->discover('https://example.com/blog');

        self::assertFalse($result->isDirectFeed);
        self::assertNull($result->scrapeFailureReason);
        self::assertCount(2, $result->candidates);
        self::assertSame('https://example.com/rss.xml', $result->candidates[0]->url);
        self::assertSame('Main', $result->candidates[0]->title);
        self::assertSame('rss', $result->candidates[0]->format);
        self::assertSame('https://cdn.example.com/atom', $result->candidates[1]->url);
        self::assertNull($result->candidates[1]->title);
        self::assertSame('atom', $result->candidates[1]->format);
        // A page advertising native feeds gets NO synthetic scraped candidate
        // next to them — scraping is strictly the fallback.
        foreach ($result->candidates as $candidate) {
            self::assertNotSame('scraped', $candidate->format);
        }
    }

    /**
     * The heise homepage snapshot advertises no feed autodiscovery links (its
     * rel="alternate" links are hreflang language alternates), but its article
     * list extracts — the page itself becomes the one 'scraped' candidate,
     * keyed by the fetch's FINAL url so the later subscribe fetches the same
     * canonical address.
     */
    public function testFeedlessPageFallsBackToOneScrapedCandidate(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://www.heise.de',
            'https://www.heise.de/',
            $this->scrapedFixture('heise-2026-07-23.html'),
        );

        $result = $this->discovery($fetcher)->discover('https://www.heise.de');

        self::assertFalse($result->isDirectFeed);
        self::assertNull($result->scrapeFailureReason);
        self::assertCount(1, $result->candidates);
        self::assertSame('https://www.heise.de/', $result->candidates[0]->url);
        self::assertSame('scraped', $result->candidates[0]->format);
        self::assertNotNull($result->candidates[0]->title);
    }

    public function testAccessDeniedStatusReportsBlocked(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://forbidden.example.com', new FeedUnreachableException('x: HTTP 403', 403));

        $result = $this->discovery($fetcher)->discover('https://forbidden.example.com');

        self::assertFalse($result->isDirectFeed);
        self::assertSame('blocked', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testTransportFailureReportsUnreachable(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://nxdomain.example.com', new FeedUnreachableException('DNS', null));

        $result = $this->discovery($fetcher)->discover('https://nxdomain.example.com');

        self::assertSame('unreachable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testSsrfBlockedFetchReportsUnreachable(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://internal.example.com', new SsrfBlockedException('private address'));

        $result = $this->discovery($fetcher)->discover('https://internal.example.com');

        self::assertSame('unreachable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testArticleFreePageReportsNotScrapable(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://example.com/plain',
            'https://example.com/plain',
            $this->scrapedFixture('nav-only.html'),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/plain');

        self::assertFalse($result->isDirectFeed);
        self::assertSame('not_scrapable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testEmptyBodyReportsNotScrapable(): void
    {
        $fetcher = $this->fetcherReturning('https://example.com/empty', 'https://example.com/empty', '   ');

        $result = $this->discovery($fetcher)->discover('https://example.com/empty');

        self::assertSame('not_scrapable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }
}
