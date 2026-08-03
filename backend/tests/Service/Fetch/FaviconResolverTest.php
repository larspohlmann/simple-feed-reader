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
        // @lang TEXT: every caller passes a deliberately fake icon path, because
        // resolving those paths is what the tests are about. Turning the HTML
        // injection off keeps PhpStorm from reporting them as unresolvable, and
        // from asking for a `lang` attribute the fixture does not need.
        return FetchResponse::fetched(
            'https://blog.example.com/',
            permanentRedirect: false,
            body: /** @lang TEXT */ '<!doctype html><html><head>' . $head . '</head><body>x</body></html>',
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

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://cdn.example.com/fav.png', $icons[1]);
    }

    public function testResolvesRelativeIconAgainstTheFinalUrl(): void
    {
        $fetcher = new StubFeedFetcher();
        // @lang TEXT: deliberately fake icon paths — resolving them is what the
        // test asserts — so the "cannot resolve file" hint is wrong here.
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(/** @lang TEXT */ '<link rel="shortcut icon" href="/assets/icon.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/assets/icon.png', $icons[1]);
    }

    public function testPrefersTheLargestDeclaredSize(): void
    {
        $fetcher = new StubFeedFetcher();
        // Deliberately fake icon paths — picking the larger of them is what the
        // test asserts — so the "cannot resolve file" hint is wrong here.
        /** @noinspection HtmlUnknownTarget */
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page(
                '<link rel="icon" sizes="16x16" href="/small.png">'
                . '<link rel="icon" sizes="64x64" href="/big.png">',
            ),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/big.png', $icons[1]);
    }

    public function testRejectsInsecureIconAndFallsBackToFaviconIco(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://blog.example.com',
            $this->page('<link rel="icon" href="http://insecure.example.com/fav.png">'),
        );

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        // A http icon is mixed-content-blocked in the https app, so it is
        // rejected in favour of the https /favicon.ico convention.
        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testFallsBackToFaviconIcoWhenThePageDeclaresNoIcon(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://blog.example.com', $this->page('<title>No icon here</title>'));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testFallsBackToFaviconIcoWhenTheFetchFails(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://blog.example.com', new FeedUnreachableException('boom'));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'https://blog.example.com/']);

        // Best-effort: a favicon fetch failure must never propagate to the
        // refresh that called it, so a sensible fallback is still returned.
        self::assertSame('https://blog.example.com/favicon.ico', $icons[1]);
    }

    public function testDerivesTheHostFromTheBaseIgnoringSchemeAndPath(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn('https://news.example.com', FetchResponse::fetched(
            'https://news.example.com/',
            permanentRedirect: false,
            body: '<!doctype html><html lang="en"><head></head><body>x</body></html>',
            etag: null,
            lastModified: null,
        ));

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'http://news.example.com/some/feed.xml']);

        // A http feed URL with a path still resolves the favicon on the https
        // host root — the app renders favicons over https only.
        self::assertSame('https://news.example.com/favicon.ico', $icons[1]);
    }

    public function testReturnsNullWhenTheBaseHasNoHost(): void
    {
        $fetcher = new StubFeedFetcher();

        $icons = $this->resolver($fetcher)->resolveAll([1 => 'not a url']);

        self::assertNull($icons[1]);
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testResolvesManySitesInOneBatch(): void
    {
        $fetcher = new StubFeedFetcher();
        // @lang TEXT: `/a.png` must stay a fake path — resolving it is the point
        // of the test — so the "cannot resolve file" hint is wrong here.
        $fetcher->willReturn(
            'https://one.example.com',
            FetchResponse::fetched(
                'https://one.example.com',
                false,
                /** @lang TEXT */ '<link rel="icon" href="/a.png">',
                null,
                null,
            ),
        );
        $fetcher->willReturn(
            'https://two.example.com',
            FetchResponse::fetched('https://two.example.com', false, '<html lang="en"></html>', null, null),
        );

        $icons = $this->resolver($fetcher)->resolveAll([
            7 => 'https://one.example.com/feed',
            9 => 'https://two.example.com/feed',
        ]);

        self::assertSame('https://one.example.com/a.png', $icons[7]);
        // No <link> tag, so the /favicon.ico convention stands in.
        self::assertSame('https://two.example.com/favicon.ico', $icons[9]);
    }

    public function testAFailedHomepageFetchYieldsTheConventionalFallback(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://one.example.com', new FeedUnreachableException('boom'));

        $icons = $this->resolver($fetcher)->resolveAll([7 => 'https://one.example.com/feed']);

        self::assertSame('https://one.example.com/favicon.ico', $icons[7]);
    }

    public function testAUrlWithoutAHostYieldsNoIcon(): void
    {
        $icons = $this->resolver(new StubFeedFetcher())->resolveAll([7 => 'not a url']);

        self::assertNull($icons[7]);
    }

    /**
     * BatchFeedFetcherInterface::fetchAll() only promises never to throw for
     * an individual site's outcome — an invariant violation inside the
     * fetcher itself (StubFeedFetcher's own "you forgot to stub this URL"
     * guard is a stand-in for one) is a different failure shape entirely,
     * and it must not escape resolveAll(): every site still gets the
     * conventional fallback rather than blowing up the whole batch.
     */
    public function testABatchLevelFailureDegradesEverySiteToTheFallback(): void
    {
        // No stubs configured at all, so the very first ticket makes the
        // generator throw instead of yielding a FetchOutcome.
        $fetcher = new StubFeedFetcher();

        $icons = $this->resolver($fetcher)->resolveAll([
            7 => 'https://one.example.com/feed',
            9 => 'https://two.example.com/feed',
        ]);

        self::assertSame('https://one.example.com/favicon.ico', $icons[7]);
        self::assertSame('https://two.example.com/favicon.ico', $icons[9]);
    }
}
