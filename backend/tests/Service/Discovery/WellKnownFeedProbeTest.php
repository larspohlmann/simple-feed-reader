<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\WellKnownFeedProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WellKnownFeedProbeTest extends KernelTestCase
{
    private function probe(StubFeedFetcher $fetcher): WellKnownFeedProbe
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return new WellKnownFeedProbe($fetcher, $parser);
    }

    private function feedXml(): string
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        return $xml;
    }

    /** Answers every URL the probe can ask for, so no probe hits the stub's "not stubbed" guard. */
    private function fetcherMissingEverything(string $pageUrl): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        foreach (WellKnownFeedProbe::SUFFIXES as $suffix) {
            $fetcher->willThrow(
                $this->directoryOf($pageUrl) . $suffix,
                new FeedUnreachableException('x: HTTP 404', 404),
            );
        }

        return $fetcher;
    }

    private function directoryOf(string $pageUrl): string
    {
        return str_ends_with($pageUrl, '/') ? $pageUrl : $pageUrl . '/';
    }

    public function testItSubscribesTheFeedRedditServesUnderTheRefusedPage(): void
    {
        $page = 'https://www.reddit.com/r/Bitwig/';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willReturn($page . '.rss', FetchResponse::fetched(
            $page . '.rss',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame($page . '.rss', $this->probe($fetcher)->probe($page));
        self::assertSame([$page . '.rss'], $fetcher->fetchedUrls);
    }

    public function testItWalksTheSuffixesInOrderAndStopsAtTheFirstFeed(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willReturn($page . 'rss', FetchResponse::fetched(
            $page . 'rss',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame($page . 'rss', $this->probe($fetcher)->probe($page));
        self::assertSame(
            [$page . '.rss', $page . 'feed', $page . 'rss'],
            $fetcher->fetchedUrls,
        );
    }

    public function testItTreatsTheEnteredUrlAsADirectory(): void
    {
        $page = 'https://www.reddit.com/r/Bitwig';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willReturn('https://www.reddit.com/r/Bitwig/.rss', FetchResponse::fetched(
            'https://www.reddit.com/r/Bitwig/.rss',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame('https://www.reddit.com/r/Bitwig/.rss', $this->probe($fetcher)->probe($page));
    }

    public function testItDropsQueryAndFragment(): void
    {
        $fetcher = $this->fetcherMissingEverything('https://blocked.example.com/blog/');
        $fetcher->willReturn('https://blocked.example.com/blog/.rss', FetchResponse::fetched(
            'https://blocked.example.com/blog/.rss',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame(
            'https://blocked.example.com/blog/.rss',
            $this->probe($fetcher)->probe('https://blocked.example.com/blog/?sort=new#top'),
        );
    }

    public function testItReportsTheFinalUrlOfARedirectedProbe(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willReturn($page . '.rss', FetchResponse::fetched(
            'https://feeds.example.com/blog.xml',
            permanentRedirect: true,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame('https://feeds.example.com/blog.xml', $this->probe($fetcher)->probe($page));
    }

    public function testItSkipsABodyThatIsNotAFeed(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willReturn($page . '.rss', FetchResponse::fetched(
            $page . '.rss',
            permanentRedirect: false,
            body: '<!doctype html><html><body>Not a feed</body></html>',
            etag: null,
            lastModified: null,
        ));
        $fetcher->willReturn($page . 'feed', FetchResponse::fetched(
            $page . 'feed',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame($page . 'feed', $this->probe($fetcher)->probe($page));
    }

    public function testItKeepsProbingAfterAFetchFailure(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcherMissingEverything($page);
        $fetcher->willThrow($page . '.rss', new SsrfBlockedException('private address'));
        $fetcher->willReturn($page . 'feed', FetchResponse::fetched(
            $page . 'feed',
            permanentRedirect: false,
            body: $this->feedXml(),
            etag: null,
            lastModified: null,
        ));

        self::assertSame($page . 'feed', $this->probe($fetcher)->probe($page));
    }

    public function testItFindsNothingWhenNoConventionalPathServesAFeed(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcherMissingEverything($page);

        self::assertNull($this->probe($fetcher)->probe($page));
        self::assertCount(\count(WellKnownFeedProbe::SUFFIXES), $fetcher->fetchedUrls);
    }

    /**
     * A refused feed ADDRESS (a 429 from Reddit's rate limiter, typically) is
     * not a page hiding a feed somewhere below it — probing `/.rss/.rss` can
     * only add load to a host that just asked us to slow down.
     */
    public function testItProbesNothingWhenTheUrlIsAlreadyAFeedAddress(): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull($this->probe($fetcher)->probe('https://www.reddit.com/r/Bitwig/.rss'));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testItProbesNothingForAUrlWithoutAHost(): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull($this->probe($fetcher)->probe('not-a-url'));
        self::assertSame([], $fetcher->fetchedUrls);
    }
}
