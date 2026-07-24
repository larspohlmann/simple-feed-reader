<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FaviconResolverTest extends TestCase
{
    private function resolver(StubFeedFetcher $fetcher): FaviconResolver
    {
        return new FaviconResolver($fetcher, new NullLogger());
    }

    private function page(string $head): FetchResponse
    {
        return FetchResponse::fetched(
            'https://blog.example.com/',
            permanentRedirect: false,
            body: '<!doctype html><html><head>' . $head . '</head><body>x</body></html>',
            etag: null,
            lastModified: null,
        );
    }

    public function testParsesAbsoluteIconLink(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="https://cdn.example.com/fav.png">'),
        );

        self::assertSame(
            'https://cdn.example.com/fav.png',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testResolvesRelativeIconAgainstTheFinalUrl(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="shortcut icon" href="/assets/icon.png">'),
        );

        self::assertSame(
            'https://blog.example.com/assets/icon.png',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testPrefersTheLargestDeclaredSize(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(
                '<link rel="icon" sizes="16x16" href="/small.png">'
                . '<link rel="icon" sizes="64x64" href="/big.png">',
            ),
        );

        self::assertSame(
            'https://blog.example.com/big.png',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testRejectsInsecureIconAndFallsBackToFaviconIco(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="http://insecure.example.com/fav.png">'),
        );

        // A http icon is mixed-content-blocked in the https app, so it is
        // rejected in favour of the https /favicon.ico convention.
        self::assertSame(
            'https://blog.example.com/favicon.ico',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testFallsBackToFaviconIcoWhenThePageDeclaresNoIcon(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://blog.example.com', $this->page('<title>No icon here</title>'));

        self::assertSame(
            'https://blog.example.com/favicon.ico',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testFallsBackToFaviconIcoWhenTheFetchFails(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://blog.example.com', new FeedUnreachableException('boom'));

        // Best-effort: a favicon fetch failure must never propagate to the
        // refresh that called it, so a sensible fallback is still returned.
        self::assertSame(
            'https://blog.example.com/favicon.ico',
            $this->resolver($fetcher)->resolve('https://blog.example.com/'),
        );
    }

    public function testDerivesTheHostFromTheBaseIgnoringSchemeAndPath(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://news.example.com', FetchResponse::fetched(
            'https://news.example.com/',
            permanentRedirect: false,
            body: '<!doctype html><html><head></head><body>x</body></html>',
            etag: null,
            lastModified: null,
        ));

        // A http feed URL with a path still resolves the favicon on the https
        // host root — the app renders favicons over https only.
        self::assertSame(
            'https://news.example.com/favicon.ico',
            $this->resolver($fetcher)->resolve('http://news.example.com/some/feed.xml'),
        );
    }

    public function testReturnsNullWhenTheBaseHasNoHost(): void
    {
        $fetcher = new StubFeedFetcher();

        self::assertNull($this->resolver($fetcher)->resolve('not a url'));
        self::assertNull($this->resolver($fetcher)->resolve(null));
        self::assertSame([], $fetcher->fetchedUrls);
    }
}
