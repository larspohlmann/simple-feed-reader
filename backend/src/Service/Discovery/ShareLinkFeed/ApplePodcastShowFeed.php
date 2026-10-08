<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;

/**
 * Maps an Apple Podcasts show or episode share link to the show's RSS feed through Apple's public lookup API. Every
 * failure is a null, and discovery still fetches and parses the result, so this can only ever add a subscription.
 */
final readonly class ApplePodcastShowFeed implements ShareLinkFeedInterface
{
    private const array SHARE_HOSTS = ['podcasts.apple.com', 'itunes.apple.com'];

    /** `/<storefront>/podcast/<slug>/id<showId>`; the storefront and the slug are optional, the query is ignored. */
    private const string SHOW_PATH = '#^/(?:[a-z]{2}/)?podcast/(?:[^/]+/)?id(\d+)/?$#i';

    private const string LOOKUP_API = 'https://itunes.apple.com/lookup?id=%s';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function feedUrl(string $enteredUrl): ?string
    {
        $showId = $this->showId($enteredUrl);

        return null === $showId ? null : $this->lookedUpFeedUrl($showId);
    }

    private function showId(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        if (!\in_array($host, self::SHARE_HOSTS, true)) {
            return null;
        }

        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);

        return 1 === preg_match(self::SHOW_PATH, $path, $match) ? $match[1] : null;
    }

    private function lookedUpFeedUrl(string $showId): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::LOOKUP_API, $showId));
        } catch (FetchException) {
            return null;
        }

        return $this->feedUrlOf($response->modifiedBody());
    }

    /** Reads and validates `results[0].feedUrl` out of a lookup body whose first result is a podcast. */
    private function feedUrlOf(string $lookupJson): ?string
    {
        $lookup = json_decode($lookupJson, true);
        $results = \is_array($lookup) ? ($lookup['results'] ?? null) : null;
        $show = \is_array($results) ? ($results[0] ?? null) : null;
        if (!\is_array($show) || 'podcast' !== ($show['kind'] ?? null)) {
            return null;
        }

        $feedUrl = $show['feedUrl'] ?? null;

        return \is_string($feedUrl) && $this->isAbsoluteHttpUrl($feedUrl) ? $feedUrl : null;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return '' !== (string) parse_url($url, PHP_URL_HOST) && \in_array($scheme, ['http', 'https'], true);
    }
}
