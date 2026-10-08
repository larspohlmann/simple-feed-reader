<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * Maps an Apple Podcasts show or episode share link to the show's RSS feed through Apple's public lookup API. Every
 * failure is a null, and discovery still fetches and parses the result, so this can only ever add a subscription.
 */
final readonly class ApplePodcastShowFeed implements ShareLinkFeedInterface
{
    private const array SHARE_HOSTS = ['podcasts.apple.com', 'itunes.apple.com'];

    /** `/<storefront>/podcast/<slug>/id<showId>`; the storefront and the slug are optional. */
    private const string SHOW_PATH = '#^/(?:[a-z]{2}/)?podcast/(?:[^/]+/)?id(\d+)/?$#i';

    private const string LOOKUP_API = 'https://itunes.apple.com/lookup?id=%s';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function feedUrl(string $enteredUrl): ?string
    {
        $showId = $this->showId($enteredUrl);
        if (null === $showId) {
            return null;
        }

        return $this->lookedUpFeedUrl($showId);
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

    private function feedUrlOf(string $lookupJson): ?string
    {
        $lookup = json_decode($lookupJson, true);
        $results = \is_array($lookup) ? ($lookup['results'] ?? null) : null;
        $show = \is_array($results) ? ($results[0] ?? null) : null;
        if (!\is_array($show) || 'podcast' !== ($show['kind'] ?? null)) {
            return null;
        }

        $feedUrl = $show['feedUrl'] ?? null;

        return \is_string($feedUrl) ? AbsoluteHttpUrl::orNull($feedUrl) : null;
    }
}
