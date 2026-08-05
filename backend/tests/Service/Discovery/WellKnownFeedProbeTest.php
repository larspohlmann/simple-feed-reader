<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\WellKnownFeedProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WellKnownFeedProbeTest extends KernelTestCase
{
    /** A site that serves nothing but the one URL a test stubs. */
    private function fetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));

        return $fetcher;
    }

    private function probe(StubFeedFetcher $fetcher): WellKnownFeedProbe
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return new WellKnownFeedProbe($fetcher, $parser);
    }

    private function feedAt(string $url, ?string $finalUrl = null): FetchResponse
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        return FetchResponse::fetched(
            $finalUrl ?? $url,
            permanentRedirect: false,
            body: $xml,
            etag: null,
            lastModified: null,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageUrls(): iterable
    {
        yield 'the #283 case' => [
            'https://www.reddit.com/r/Bitwig/',
            'https://www.reddit.com/r/Bitwig/.rss',
        ];
        // A section address is usually written without a trailing slash, and
        // RFC 3986 would resolve `.rss` against it one level too high.
        yield 'a path without a trailing slash' => [
            'https://www.reddit.com/r/Bitwig',
            'https://www.reddit.com/r/Bitwig/.rss',
        ];
        yield 'a query and a fragment' => [
            'https://example.com/blog/?sort=new#top',
            'https://example.com/blog/.rss',
        ];
        yield 'a bare host' => ['https://example.com', 'https://example.com/.rss'];
    }

    #[DataProvider('pageUrls')]
    public function testItAsksForTheConventionalPathUnderTheEnteredUrl(string $page, string $feed): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn($feed, $this->feedAt($feed));

        self::assertSame($feed, $this->probe($fetcher)->probe($page)?->url);
    }

    public function testItPrefersTheLikelierSuffixWhenSeveralServeAFeed(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcher();
        $fetcher->willReturn($page . 'rss', $this->feedAt($page . 'rss'));
        $fetcher->willReturn($page . 'index.xml', $this->feedAt($page . 'index.xml'));
        $fetcher->willReturn($page . 'feed', $this->feedAt($page . 'feed'));

        self::assertSame($page . 'feed', $this->probe($fetcher)->probe($page)?->url);
    }

    public function testItAsksForEveryConventionalPathAtOnce(): void
    {
        $page = 'https://blocked.example.com/blog/';

        $fetcher = $this->fetcher();
        self::assertNull($this->probe($fetcher)->probe($page));

        // The whole walk goes out together — one round trip, not six — so a
        // slow host cannot hold the subscribe request for six timeouts.
        self::assertSame([
            $page . '.rss',
            $page . 'feed',
            $page . 'rss',
            $page . 'feed.xml',
            $page . 'atom.xml',
            $page . 'index.xml',
        ], $fetcher->fetchedUrls);
    }

    public function testItCarriesTheDocumentItAlreadyRead(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcher();
        $fetcher->willReturn($page . '.rss', $this->feedAt($page . '.rss'));

        // The subscribe stores these entries instead of fetching the URL again.
        $document = $this->probe($fetcher)->probe($page)?->document;

        self::assertNotNull($document);
        self::assertSame('Example Tech Blog', $document->title);
        self::assertNotSame([], $document->entries);
    }

    public function testItReportsTheFinalUrlOfARedirectedProbe(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcher();
        $fetcher->willReturn($page . '.rss', $this->feedAt($page . '.rss', 'https://feeds.example.com/blog.xml'));

        self::assertSame('https://feeds.example.com/blog.xml', $this->probe($fetcher)->probe($page)?->url);
    }

    public function testItSkipsABodyThatIsNotAFeed(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcher();
        $fetcher->willReturn($page . '.rss', FetchResponse::fetched(
            $page . '.rss',
            permanentRedirect: false,
            body: '<!doctype html><html><body>Not a feed</body></html>',
            etag: null,
            lastModified: null,
        ));
        $fetcher->willReturn($page . 'feed', $this->feedAt($page . 'feed'));

        self::assertSame($page . 'feed', $this->probe($fetcher)->probe($page)?->url);
    }

    public function testItKeepsTheOtherAnswersWhenOneProbeFails(): void
    {
        $page = 'https://blocked.example.com/blog/';
        $fetcher = $this->fetcher();
        $fetcher->willThrow($page . '.rss', new SsrfBlockedException('private address'));
        $fetcher->willReturn($page . 'feed', $this->feedAt($page . 'feed'));

        self::assertSame($page . 'feed', $this->probe($fetcher)->probe($page)?->url);
    }

    public function testItFindsNothingWhenNoConventionalPathServesAFeed(): void
    {
        self::assertNull($this->probe($this->fetcher())->probe('https://blocked.example.com/blog/'));
    }

    /**
     * A refused feed ADDRESS (a 429 from Reddit's rate limiter, typically) is
     * not a page hiding a feed somewhere below it — probing `/.rss/.rss` can
     * only add load to a host that just asked us to slow down.
     */
    public function testItProbesNothingWhenTheUrlIsAlreadyAFeedAddress(): void
    {
        $fetcher = $this->fetcher();

        self::assertNull($this->probe($fetcher)->probe('https://www.reddit.com/r/Bitwig/.rss'));
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testItProbesNothingForAUrlWithoutAHost(): void
    {
        $fetcher = $this->fetcher();

        self::assertNull($this->probe($fetcher)->probe('not-a-url'));
        self::assertSame([], $fetcher->fetchedUrls);
    }
}
