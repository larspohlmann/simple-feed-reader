<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Enum\ScrapeFallback;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchResponse;
use App\Tests\Service\Scraper\ScrapedFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;
    use ScrapedFixtures;

    public function testDirectFeedUrlReturnsCanonicalFinalUrl(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning('https://example.com/feed', 'https://example.com/feed.xml', $xml);

        $result = $this->discovery($fetcher)->discover('https://example.com/feed', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://example.com/feed.xml', $result->feed->url);
        self::assertNull($result->scrapeFailureReason);
    }

    public function testHtmlPageReturnsResolvedCandidates(): void
    {
        // @lang TEXT: `/rss.xml` and `/style.css` are deliberately fake paths —
        // discovering and resolving them is what the test is about — so the
        // injected-HTML "cannot resolve file" and missing-`lang` hints are wrong.
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head>
              <link rel="alternate" type="application/rss+xml" title="Main" href="/rss.xml">
              <link rel="alternate" type="application/atom+xml" href="https://cdn.example.com/atom">
              <link rel="stylesheet" href="/style.css">
            </head><body>Hello</body></html>
            HTML;

        $fetcher = $this->fetcherReturning('https://example.com/blog', 'https://example.com/blog/', $html);

        $result = $this->discovery($fetcher)->discover('https://example.com/blog', ScrapeFallback::Enabled);

        self::assertNull($result->feed);
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

    public function testOffersTheRestCandidateBeforeTheRssCandidate(): void
    {
        // @lang TEXT
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>WP Site</title>
              <link rel="alternate" type="application/rss+xml" href="/feed/">
              <link rel="https://api.w.org/" href="https://wp.example/wp-json/">
            </head><body>Hi</body></html>
            HTML;

        $fetcher = $this->fetcherReturning('https://wp.example/', 'https://wp.example/', $html);
        $fetcher->willReturn(
            'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed',
            FetchResponse::fetched(
                'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed',
                permanentRedirect: false,
                body: '[{"id":1}]',
                etag: null,
                lastModified: null,
            ),
        );

        $result = $this->discovery($fetcher)->discover('https://wp.example/', ScrapeFallback::Enabled);

        self::assertCount(2, $result->candidates);
        self::assertSame('wp-json', $result->candidates[0]->format);
        self::assertSame('https://wp.example/feed/', $result->candidates[1]->url);
        self::assertSame('rss', $result->candidates[1]->format);
    }

    public function testAGatedRestApiLeavesOnlyTheRssCandidate(): void
    {
        // @lang TEXT
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>WP Site</title>
              <link rel="alternate" type="application/rss+xml" href="/feed/">
              <link rel="https://api.w.org/" href="https://wp.example/wp-json/">
            </head><body>Hi</body></html>
            HTML;

        // Everything-else throws 404, so the posts probe fails and no candidate is offered.
        $fetcher = $this->fetcherReturning('https://wp.example/', 'https://wp.example/', $html);

        $result = $this->discovery($fetcher)->discover('https://wp.example/', ScrapeFallback::Enabled);

        self::assertCount(1, $result->candidates);
        self::assertSame('rss', $result->candidates[0]->format);
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

        $result = $this->discovery($fetcher)->discover('https://www.heise.de', ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertNull($result->scrapeFailureReason);
        self::assertCount(1, $result->candidates);
        self::assertSame('https://www.heise.de/', $result->candidates[0]->url);
        self::assertSame('scraped', $result->candidates[0]->format);
        self::assertNotNull($result->candidates[0]->title);
    }

    /** A page that advertises nothing but links its feed in the footer still offers it. */
    public function testAPageWithoutAutodiscoveryOffersItsFeedShapedLink(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>A blog</title></head><body>
              <a href="/blog/feed" title="RSS">feed</a>
            </body></html>
            HTML;

        $fetcher = $this->fetcherReturning('https://example.com/blog/', 'https://example.com/blog/', $html);

        $result = $this->discovery($fetcher)->discover('https://example.com/blog/', ScrapeFallback::Enabled);

        self::assertCount(1, $result->candidates);
        self::assertSame('https://example.com/blog/feed', $result->candidates[0]->url);
        self::assertSame('feed', $result->candidates[0]->format);
    }

    /** A real feed under a conventional path beats a feed synthesized from the page. */
    public function testAScrapablePageStillPrefersAConventionalFeed(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning(
            'https://www.heise.de',
            'https://www.heise.de/',
            $this->scrapedFixture('heise-2026-07-23.html'),
        );
        $fetcher->willReturn('https://www.heise.de/.rss', FetchResponse::fetched(
            'https://www.heise.de/.rss',
            permanentRedirect: false,
            body: $xml,
            etag: null,
            lastModified: null,
        ));

        $result = $this->discovery($fetcher)->discover('https://www.heise.de', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://www.heise.de/.rss', $result->feed->url);
    }

    public function testAccessDeniedStatusReportsBlockedWhenNoConventionalPathServesAFeed(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://forbidden.example.com', new FeedUnreachableException('x: HTTP 403', 403));

        $result = $this->discovery($fetcher)->discover('https://forbidden.example.com', ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertSame('blocked', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    /**
     * A bot gate refuses with a success status: SiteGround answers 202 and a
     * meta refresh to its captcha. Nothing in the status says "refused", so
     * without recognising the body the user is told no feed exists at an address
     * that serves one — which is what three subscriptions hit from the Strato
     * box (#424). The scrape fallback is enabled here on purpose: a challenge
     * page must not become a scraped candidate either.
     */
    public function testACaptchaChallengeReportsBlockedRatherThanNoFeed(): void
    {
        // @lang TEXT: the gate's own body, kept as served.
        $challenge = /** @lang TEXT */ '<html><head><link rel="icon" href="data:;">'
            . '<meta http-equiv="refresh" content="0;/.well-known/sgcaptcha/'
            . '?r=%2Ffeed%2F&y=ipc:81.169.144.135:1786832352.672"></meta></head></html>';

        $fetcher = $this->fetcherReturning(
            'https://decodedmagazine.com/feed/',
            'https://decodedmagazine.com/feed/',
            $challenge,
        );

        $result = $this->discovery($fetcher)->discover(
            'https://decodedmagazine.com/feed/',
            ScrapeFallback::Enabled,
        );

        self::assertNull($result->feed);
        self::assertSame('blocked', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    /**
     * The #283 case: the site refuses its own page but serves the feed under a
     * conventional path, so the subscribe completes instead of dead-ending.
     */
    public function testARefusedPageSubscribesTheFeedFoundUnderAConventionalPath(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://www.reddit.com/r/Bitwig/', new FeedUnreachableException('x: HTTP 403', 403));
        $fetcher->willReturn('https://www.reddit.com/r/Bitwig/.rss', FetchResponse::fetched(
            'https://www.reddit.com/r/Bitwig/.rss',
            permanentRedirect: false,
            body: $xml,
            etag: null,
            lastModified: null,
        ));

        $result = $this->discovery($fetcher)->discover('https://www.reddit.com/r/Bitwig/', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://www.reddit.com/r/Bitwig/.rss', $result->feed->url);
        self::assertNull($result->scrapeFailureReason);
    }

    /** A 404 is an answer from a live site, so the conventional paths are worth asking for. */
    public function testAMissingPageSubscribesTheFeedFoundUnderAConventionalPath(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://example.com/blog/', new FeedUnreachableException('x: HTTP 404', 404));
        $fetcher->willReturn('https://example.com/blog/feed', FetchResponse::fetched(
            'https://example.com/blog/feed',
            permanentRedirect: false,
            body: $xml,
            etag: null,
            lastModified: null,
        ));

        $result = $this->discovery($fetcher)->discover('https://example.com/blog/', ScrapeFallback::Enabled);

        self::assertNotNull($result->feed);
        self::assertSame('https://example.com/blog/feed', $result->feed->url);
    }

    public function testAMissingPageWithNoFeedAnywhereStillReportsUnreachable(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://example.com/typo', new FeedUnreachableException('x: HTTP 404', 404));

        $result = $this->discovery($fetcher)->discover('https://example.com/typo', ScrapeFallback::Enabled);

        self::assertSame('unreachable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    /**
     * The site has just asked us to slow down; six parallel guesses are the
     * opposite of that, and each would draw its own 429 anyway.
     */
    public function testARationingSiteIsNotAskedSixMoreQuestions(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://www.reddit.com/r/Bitwig/', new FeedThrottledException('x: HTTP 429', 60));

        $result = $this->discovery($fetcher)->discover('https://www.reddit.com/r/Bitwig/', ScrapeFallback::Enabled);

        // Its own reason: "slow down" is not "you may not have this".
        self::assertSame('throttled', $result->scrapeFailureReason);
        self::assertSame(['https://www.reddit.com/r/Bitwig/'], $fetcher->fetchedUrls);
    }

    /**
     * A server that is failing is failing for its feed too, and every guess
     * would land on the same fault — so an outage costs one request, not seven.
     */
    public function testAServerErrorReportsUnreachableWithoutProbing(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://outage.example.com', new FeedUnreachableException('x: HTTP 503', 503));

        $result = $this->discovery($fetcher)->discover('https://outage.example.com', ScrapeFallback::Enabled);

        self::assertSame('unreachable', $result->scrapeFailureReason);
        self::assertSame(['https://outage.example.com'], $fetcher->fetchedUrls);
    }

    /**
     * Nothing answered the first request, so nothing will answer six more —
     * each probe would cost a full timeout to confirm the host is still gone.
     */
    public function testTransportFailureReportsUnreachableWithoutProbing(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://nxdomain.example.com', new FeedUnreachableException('DNS', null));

        $result = $this->discovery($fetcher)->discover('https://nxdomain.example.com', ScrapeFallback::Enabled);

        self::assertSame('unreachable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
        self::assertSame(['https://nxdomain.example.com'], $fetcher->fetchedUrls);
    }

    public function testSsrfBlockedFetchReportsUnreachable(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://internal.example.com', new SsrfBlockedException('private address'));

        $result = $this->discovery($fetcher)->discover('https://internal.example.com', ScrapeFallback::Enabled);

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

        $result = $this->discovery($fetcher)->discover('https://example.com/plain', ScrapeFallback::Enabled);

        self::assertNull($result->feed);
        self::assertSame('not_scrapable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testEmptyBodyReportsNotScrapable(): void
    {
        $fetcher = $this->fetcherReturning('https://example.com/empty', 'https://example.com/empty', '   ');

        $result = $this->discovery($fetcher)->discover('https://example.com/empty', ScrapeFallback::Enabled);

        self::assertSame('not_scrapable', $result->scrapeFailureReason);
        self::assertSame([], $result->candidates);
    }

    public function testAScrapablePageOffersNoCandidateWhenTheFallbackIsDisabled(): void
    {
        $fetcher = $this->fetcherReturning(
            'https://example.com/blog',
            'https://example.com/blog',
            $this->scrapedFixture('heise-2026-07-23.html'),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/blog', ScrapeFallback::Disabled);

        self::assertNull($result->feed);
        self::assertSame([], $result->candidates);
        // A null reason is what makes the dialog render its plain "no feed
        // found" state instead of a scrape-flavoured warning.
        self::assertNull($result->scrapeFailureReason);
    }

    public function testAnEmptyBodyReportsNoReasonWhenTheFallbackIsDisabled(): void
    {
        $fetcher = $this->fetcherReturning('https://example.com/blank', 'https://example.com/blank', '   ');

        $result = $this->discovery($fetcher)->discover('https://example.com/blank', ScrapeFallback::Disabled);

        self::assertSame([], $result->candidates);
        self::assertNull($result->scrapeFailureReason);
    }

    public function testAnUnreachableSiteStillReportsItsReasonWhenTheFallbackIsDisabled(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow('https://example.com/gone', new FeedUnreachableException('gone', 404));

        $result = $this->discovery($fetcher)->discover('https://example.com/gone', ScrapeFallback::Disabled);

        self::assertSame('unreachable', $result->scrapeFailureReason);
    }
}
