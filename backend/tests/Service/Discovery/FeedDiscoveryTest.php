<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Exception\FeedDiscoveryException;
use App\Service\Discovery\FeedDiscovery;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedDiscoveryTest extends KernelTestCase
{
    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return new FeedDiscovery($fetcher, $parser);
    }

    public function testDirectFeedUrlReturnsCanonicalFinalUrl(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/feed',
            FetchResponse::fetched(
                'https://example.com/feed.xml',
                permanentRedirect: false,
                body: $xml,
                etag: null,
                lastModified: null,
            ),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/feed');

        self::assertTrue($result->isDirectFeed);
        self::assertSame('https://example.com/feed.xml', $result->feedUrl);
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

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/blog',
            FetchResponse::fetched(
                'https://example.com/blog/',
                permanentRedirect: false,
                body: $html,
                etag: null,
                lastModified: null,
            ),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/blog');

        self::assertFalse($result->isDirectFeed);
        self::assertCount(2, $result->candidates);
        self::assertSame('https://example.com/rss.xml', $result->candidates[0]->url);
        self::assertSame('Main', $result->candidates[0]->title);
        self::assertSame('rss', $result->candidates[0]->format);
        self::assertSame('https://cdn.example.com/atom', $result->candidates[1]->url);
        self::assertNull($result->candidates[1]->title);
        self::assertSame('atom', $result->candidates[1]->format);
    }

    public function testHtmlWithoutFeedLinksThrowsDiscoveryException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/plain',
            FetchResponse::fetched(
                'https://example.com/plain',
                permanentRedirect: false,
                body: '<html><body>nothing here</body></html>',
                etag: null,
                lastModified: null,
            ),
        );

        $this->expectException(FeedDiscoveryException::class);
        $this->discovery($fetcher)->discover('https://example.com/plain');
    }

    public function testFetchFailureBecomesDiscoveryException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://blocked.example.com', new FeedUnreachableException('blocked by SSRF guard'));

        $this->expectException(FeedDiscoveryException::class);
        $this->discovery($fetcher)->discover('https://blocked.example.com');
    }
}
