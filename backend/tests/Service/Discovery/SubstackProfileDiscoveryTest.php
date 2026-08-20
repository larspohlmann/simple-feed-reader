<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Enum\ScrapeFallback;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Discovery integrated with the Substack profile rewrite — proof that
 * FeedDiscovery consults SubstackProfileFeed and then fetch-and-parses whatever
 * it resolves. The rewrite's own edge cases (which URLs it recognises, how it
 * reads the profile API) are SubstackProfileFeedTest's job; this file only
 * proves the two are wired together, so the Substack-specific fixtures stay out
 * of the generic FeedDiscoveryTest.
 */
final class SubstackProfileDiscoveryTest extends KernelTestCase
{
    use BuildsFeedDiscovery;

    /**
     * "Copy link to profile" gives `substack.com/@handle`, whose feed lives on
     * a host the same-origin probe cannot reach — and whose subdomain need not
     * be the handle. `@abbeyheffer` publishes at `theopenbookshelf`; discovery
     * reads that from the profile API, subscribes it, and drops the share URL's
     * tracking query.
     */
    public function testASubstackProfileSubscribesThePublicationApiResolvesForIt(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = $this->fetcherReturning(
            'https://theopenbookshelf.substack.com/feed',
            'https://theopenbookshelf.substack.com/feed',
            $xml,
        );
        $this->stubProfileApi($fetcher, 'abbeyheffer', 'theopenbookshelf');

        $result = $this->discovery($fetcher)->discover(
            'https://substack.com/@abbeyheffer?r=260csv&utm_medium=ios',
            ScrapeFallback::Enabled,
        );

        self::assertNotNull($result->feed);
        self::assertSame('https://theopenbookshelf.substack.com/feed', $result->feed->url);
        self::assertNull($result->scrapeFailureReason);
    }

    /**
     * A handle whose profile names no publication cannot be resolved. The
     * rewrite must not fabricate a subscription — discovery falls through to its
     * usual no-feed outcome, exactly as if the URL had never been a profile.
     */
    public function testAnUnresolvableSubstackProfileFallsThroughInsteadOfSubscribing(): void
    {
        $fetcher = $this->fetcher();
        $this->stubProfileApiRaw($fetcher, 'ghost-handle', '{"handle":"ghost-handle"}');

        $result = $this->discovery($fetcher)->discover(
            'https://substack.com/@ghost-handle',
            ScrapeFallback::Enabled,
        );

        self::assertNull($result->feed);
        self::assertSame([], $result->candidates);
    }

    /** Makes the profile API answer for $handle with a primary publication on $subdomain. */
    private function stubProfileApi(StubFeedFetcher $fetcher, string $handle, string $subdomain): void
    {
        $this->stubProfileApiRaw(
            $fetcher,
            $handle,
            (string) json_encode(['primaryPublication' => ['subdomain' => $subdomain]], JSON_THROW_ON_ERROR),
        );
    }

    /** Makes the profile API answer for $handle with a verbatim body. */
    private function stubProfileApiRaw(StubFeedFetcher $fetcher, string $handle, string $body): void
    {
        $apiUrl = sprintf('https://substack.com/api/v1/user/%s/public_profile', $handle);
        $fetcher->willReturn(
            $apiUrl,
            FetchResponse::fetched($apiUrl, permanentRedirect: false, body: $body, etag: null, lastModified: null),
        );
    }
}
