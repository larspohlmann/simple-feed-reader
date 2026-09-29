<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/**
 * The feed's <link> when a person could go there, else the feed's own origin (in a 111-feed survey, just under half
 * published no link). Never persist the guess: Feed::$siteUrl keeps what the publisher said, and a backup restores it.
 */
final class FeedWebsite
{
    /** Subdomains that serve feeds rather than a site, stripped to reach the site itself. */
    private const array FEED_SUBDOMAINS = ['feeds', 'feed', 'rss', 'atom'];

    /** Their address says nothing about who publishes the feed, so a feed found only there has no website. */
    private const array SYNDICATORS = ['feedburner.com', 'feedproxy.google.com', 'feedpress.me', 'rss.app'];

    /** A path ending this way is a feed document, whoever links to it. */
    private const string FEED_DOCUMENT_PATH = '/\.(xml|rss|atom)$/i';

    /** A public name ends in letters, or in a punycode label. */
    private const string PUBLIC_TOP_LEVEL = '/^(xn--[a-z0-9-]+|[a-z]{2,})$/i';

    public static function of(string $feedUrl, ?string $publishedLink): ?string
    {
        return self::usablePublishedLink($feedUrl, $publishedLink) ?? self::siteOrigin($feedUrl);
    }

    private static function usablePublishedLink(string $feedUrl, ?string $publishedLink): ?string
    {
        if ($publishedLink === null) {
            return null;
        }

        $host = (string) parse_url($publishedLink, \PHP_URL_HOST);
        if (!self::namesAPublicHost($host) || self::isFeedSubdomain($host)) {
            return null;
        }

        // Pointing at the feed document is pointing at what the reader already
        // has; following it lands them in raw XML.
        if (strcasecmp(rtrim($publishedLink, '/'), rtrim($feedUrl, '/')) === 0) {
            return null;
        }

        return self::isFeedDocument($publishedLink) ? null : $publishedLink;
    }

    /**
     * The feed's own origin, with a feed-serving subdomain stripped: a feed at
     * rss.politico.com belongs to politico.com, and rss.politico.com itself
     * serves no site. Stripped only while a registrable name remains.
     */
    private static function siteOrigin(string $feedUrl): ?string
    {
        $origin = UrlOrigin::of($feedUrl);
        if ($origin === null) {
            return null;
        }

        $host = (string) parse_url($origin, \PHP_URL_HOST);
        $stripped = self::isFeedSubdomain($host)
            ? implode('.', \array_slice(explode('.', $host), 1))
            : $host;

        if (self::isSyndicator($stripped)) {
            return null;
        }

        return $stripped === $host ? $origin : str_replace('//' . $host, '//' . $stripped, $origin);
    }

    private static function isSyndicator(string $host): bool
    {
        return \in_array(strtolower($host), self::SYNDICATORS, true);
    }

    private static function namesAPublicHost(string $host): bool
    {
        $labels = explode('.', $host);

        return \count($labels) >= 2 && preg_match(self::PUBLIC_TOP_LEVEL, (string) end($labels)) === 1;
    }

    private static function isFeedDocument(string $url): bool
    {
        return preg_match(self::FEED_DOCUMENT_PATH, (string) parse_url($url, \PHP_URL_PATH)) === 1;
    }

    private static function isFeedSubdomain(string $host): bool
    {
        $labels = explode('.', $host);

        return \count($labels) >= 3 && \in_array(strtolower($labels[0]), self::FEED_SUBDOMAINS, true);
    }

    private function __construct()
    {
    }
}
