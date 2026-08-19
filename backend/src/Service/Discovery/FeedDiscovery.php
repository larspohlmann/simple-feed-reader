<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\ScrapeFallback;
use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;

/**
 * Turns a user-entered URL into something to subscribe to, trying four sources
 * in decreasing order of certainty: the URL itself parsed as a feed; the feeds
 * the page points at (FeedLinkScanner, exact first and guessed second); a feed
 * under one of the conventional paths (WellKnownFeedProbe) — which is also the
 * only source left when the page never arrives, as on the sites that refuse
 * every non-browser client; and finally a synthetic 'scraped' candidate built
 * from the page's own article list.
 *
 * Discovery never throws for a bad address: failures come back as a
 * scrapeFailureReason so the subscribe endpoint can always answer with a
 * renderable outcome. Every fetch goes through the SSRF-guarded fetcher, so
 * discovery inherits the same protection as refresh.
 */
final readonly class FeedDiscovery implements FeedDiscoveryInterface
{
    /**
     * Statuses meaning "the site answered but refused us" — retrying won't help,
     * a feed URL might. 429 is NOT one of them: retrying is exactly what that
     * one asks for, and it arrives as its own FeedThrottledException.
     */
    private const array BLOCKED_STATUSES = [401, 403];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
        private FeedLinkScanner $links,
        private WellKnownFeedProbe $wellKnownFeeds,
        private BotChallengePage $botChallenge,
        private SubstackProfileFeed $substackProfile,
    ) {
    }

    public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult
    {
        // A Substack profile-share URL names its feed on another host; rewrite
        // it before the fetch so the direct-feed path below can parse-verify it.
        $url = $this->substackProfile->feedUrl($url) ?? $url;

        try {
            $response = $this->fetcher->fetch($url);
        } catch (FeedThrottledException) {
            // The site has just asked us to slow down; six parallel guesses are
            // the opposite of that, and each would draw its own 429.
            return FeedDiscoveryResult::scrapeFailed('throttled');
        } catch (FeedUnreachableException $e) {
            return $this->feedTheSiteMightStillServe($url, $e);
        } catch (FetchException) {
            // Gone, over-size, SSRF-blocked: nothing usable ever arrived.
            return FeedDiscoveryResult::scrapeFailed('unreachable');
        }

        $body = $response->body ?? '';

        try {
            // Parsing IS the test of "is this a feed?", and the document it
            // yields is what the subscribe stores — so the URL is never fetched
            // a second time to read what we are holding already.
            $document = $this->parser->parse($body);

            return FeedDiscoveryResult::directFeed(new DiscoveredFeed(
                $response->finalUrl,
                $document,
                $response->etag,
                $response->lastModified,
            ));
        } catch (FeedParseException) {
            // Not a feed — treat it as a page that may point at one.
        }

        // Unless a gate answered for the site. Its page points at no feed and
        // scrapes to nothing, so every step below would end in "no feed here" —
        // which is the one thing this answer does not mean.
        if ($this->botChallenge->wasReturned($body)) {
            return FeedDiscoveryResult::scrapeFailed('blocked');
        }

        $candidates = $this->links->scan($body, $response->finalUrl);

        return [] !== $candidates
            ? FeedDiscoveryResult::candidates($candidates)
            : $this->feedThePageNeverMentions($body, $response->finalUrl, $fallback);
    }

    /**
     * The page arrived and points at no feed at all. Before synthesizing one
     * from its article list, ask for the conventional paths: a real feed the
     * page merely forgot to advertise beats anything the scraper can build.
     */
    private function feedThePageNeverMentions(
        string $body,
        string $finalUrl,
        ScrapeFallback $fallback,
    ): FeedDiscoveryResult {
        return $this->probedFeed($finalUrl)
            ?? (ScrapeFallback::Enabled === $fallback
                ? $this->scrapeFallback($body, $finalUrl)
                : FeedDiscoveryResult::candidates([]));
    }

    /**
     * The page did not arrive, but the site may still serve a feed under it —
     * that page was the only way to LEARN the feed's address, so the probe
     * guesses it instead.
     *
     * Only worth asking when the site actually answered, and answered for
     * itself: a missing status code is a DNS failure or a dead connection, and
     * a 5xx is a server that is currently answering nothing correctly. Either
     * way the guesses would fail the same way the page did.
     */
    private function feedTheSiteMightStillServe(
        string $url,
        FeedUnreachableException $error,
    ): FeedDiscoveryResult {
        $status = $error->statusCode;
        if (null === $status || $status >= 500) {
            return FeedDiscoveryResult::scrapeFailed('unreachable');
        }

        return $this->probedFeed($url)
            ?? FeedDiscoveryResult::scrapeFailed(
                \in_array($status, self::BLOCKED_STATUSES, true) ? 'blocked' : 'unreachable',
            );
    }

    /**
     * A hit is reported as a direct feed rather than as a candidate: the probe
     * has already parsed the document, and a candidate would cost two more
     * requests (preview, then subscribe) to a host that just turned one down.
     */
    private function probedFeed(string $url): ?FeedDiscoveryResult
    {
        $probed = $this->wellKnownFeeds->probe($url);

        return null === $probed ? null : FeedDiscoveryResult::directFeed($probed);
    }

    /**
     * Last resort for pages advertising no feeds: offer the page ITSELF as a
     * 'scraped' candidate — but only after proving the extractor gets an
     * article list out of it, so the user is never offered a candidate whose
     * first refresh is guaranteed to fail. Keyed by the fetch's final URL so
     * the later subscribe stores the same canonical address.
     */
    private function scrapeFallback(string $body, string $finalUrl): FeedDiscoveryResult
    {
        try {
            $parsed = $this->extractor->extract($body, $finalUrl);
        } catch (\Throwable) {
            // Deliberately wider than HtmlExtractionException: an extractor
            // bug on exotic markup must degrade to "not scrapable", not 500
            // the subscribe endpoint.
            return FeedDiscoveryResult::scrapeFailed('not_scrapable');
        }

        return FeedDiscoveryResult::candidates([
            new FeedCandidate($finalUrl, $parsed->title, SourceFormat::SCRAPED),
        ]);
    }
}
