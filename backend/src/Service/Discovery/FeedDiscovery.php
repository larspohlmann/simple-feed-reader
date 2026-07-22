<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Exception\FeedDiscoveryException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\UrlResolver;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;

/**
 * Turns a user-entered URL into either a confirmed feed URL or a list of
 * candidate feeds discovered from an HTML page. Every fetch goes through the
 * SSRF-guarded fetcher, so discovery inherits the same protection as refresh.
 */
final readonly class FeedDiscovery implements FeedDiscoveryInterface
{
    private const FEED_LINK_TYPES = ['application/rss+xml', 'application/atom+xml'];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    public function discover(string $url): FeedDiscoveryResult
    {
        try {
            $response = $this->fetcher->fetch($url);
        } catch (FetchException $e) {
            throw new FeedDiscoveryException('The address could not be fetched.', $e);
        }

        $body = $response->body ?? '';
        if ('' === trim($body)) {
            throw new FeedDiscoveryException('The address returned an empty document.');
        }

        try {
            $this->parser->parse($body); // validates it really is a feed

            return FeedDiscoveryResult::directFeed($response->finalUrl);
        } catch (FeedParseException) {
            // Not a feed — treat as HTML and look for <link rel="alternate">.
        }

        $candidates = $this->scanHtml($body, $response->finalUrl);
        if ([] === $candidates) {
            throw new FeedDiscoveryException('No feed was found at that address.');
        }

        return FeedDiscoveryResult::candidates($candidates);
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
            if (!\in_array(strtolower(trim($link->getAttribute('type'))), self::FEED_LINK_TYPES, true)) {
                continue;
            }
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
            $candidates[] = new FeedCandidate($resolved, '' === $title ? null : $title);
        }

        return $candidates;
    }
}
