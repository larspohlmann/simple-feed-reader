<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\UrlResolver;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Scraper\HtmlItemExtractor;

/**
 * Turns a user-entered URL into a confirmed feed URL, a list of candidate
 * feeds discovered from an HTML page, or — for pages advertising no feeds at
 * all — a synthetic 'scraped' candidate backed by the HTML item extractor.
 * Discovery never throws for a bad address: failures come back as a
 * scrapeFailureReason so the subscribe endpoint can always answer with a
 * renderable outcome. Every fetch goes through the SSRF-guarded fetcher, so
 * discovery inherits the same protection as refresh.
 */
final readonly class FeedDiscovery implements FeedDiscoveryInterface
{
    private const FEED_LINK_TYPES = ['application/rss+xml', 'application/atom+xml'];

    /** Statuses meaning "the site answered but refused us" — retrying won't help, a feed URL might. */
    private const BLOCKED_STATUSES = [401, 403, 429];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
    ) {
    }

    public function discover(string $url): FeedDiscoveryResult
    {
        try {
            $response = $this->fetcher->fetch($url);
        } catch (FeedUnreachableException $e) {
            return FeedDiscoveryResult::scrapeFailed(
                \in_array($e->statusCode, self::BLOCKED_STATUSES, true) ? 'blocked' : 'unreachable',
            );
        } catch (FetchException) {
            // Gone, over-size, SSRF-blocked: nothing usable ever arrived.
            return FeedDiscoveryResult::scrapeFailed('unreachable');
        }

        $body = $response->body ?? '';
        if ('' === trim($body)) {
            return FeedDiscoveryResult::scrapeFailed('not_scrapable');
        }

        try {
            $this->parser->parse($body); // validates it really is a feed

            return FeedDiscoveryResult::directFeed($response->finalUrl);
        } catch (FeedParseException) {
            // Not a feed — treat as HTML and look for <link rel="alternate">.
        }

        $candidates = $this->scanHtml($body, $response->finalUrl);
        if ([] === $candidates) {
            return $this->scrapeFallback($body, $response->finalUrl);
        }

        return FeedDiscoveryResult::candidates($candidates);
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

    /** @return list<FeedCandidate> */
    private function scanHtml(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never let the parser dereference external entities.
        $dom->loadHTML($html, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $candidates = [];
        $seen = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            if ('alternate' !== strtolower(trim($link->getAttribute('rel')))) {
                continue;
            }
            $type = strtolower(trim($link->getAttribute('type')));
            if (!\in_array($type, self::FEED_LINK_TYPES, true)) {
                continue;
            }
            // Only the two feed types above reach here, so anything not Atom is
            // RSS. The scraper fallback path introduces its own 'scraped' value.
            $format = 'application/atom+xml' === $type ? 'atom' : 'rss';
            $href = trim($link->getAttribute('href'));
            if ('' === $href) {
                continue;
            }

            $resolved = UrlResolver::resolve($baseUrl, $href);
            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            $title = trim($link->getAttribute('title'));
            $candidates[] = new FeedCandidate($resolved, '' === $title ? null : $title, $format);
        }

        return $candidates;
    }
}
