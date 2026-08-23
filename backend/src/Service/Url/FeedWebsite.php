<?php

declare(strict_types=1);

namespace App\Service\Url;

use App\Service\Fetch\UrlResolver;

/**
 * Where to send a reader who asks to visit a feed's website.
 *
 * A feed's own <link> is the obvious answer and is wrong often enough that it
 * cannot be used unchecked. Measured against a real 111-feed library: just
 * under half publish no <link> at all; several point it at the feed document
 * the reader already has (Telepolis, Politico); one leaked an internal
 * Kubernetes service name out of its CMS (ZDFheute); one points at a `feeds.`
 * host that serves no site (Nature).
 *
 * So the published link is used only when it names somewhere a person could go,
 * and otherwise the feed's own address supplies the origin — a feed nearly
 * always lives on the site it describes.
 *
 * This is a presentation decision, never a persisted one. Feed::$siteUrl keeps
 * exactly what the publisher said: the backup carries that column, and a guess
 * written into it would restore as though the publisher had stated it.
 */
final class FeedWebsite
{
    /** Subdomains that serve feeds rather than a site, stripped to reach the site itself. */
    private const array FEED_SUBDOMAINS = ['feeds', 'feed', 'rss', 'atom'];

    /**
     * Hosts that syndicate other people's feeds. Their address says nothing
     * about who publishes the feed — the Symfony blog's feed lives on
     * feedburner.com — so when one of these is all we have, the honest answer
     * is no website rather than a link to the syndicator.
     */
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
        $origin = UrlResolver::origin($feedUrl);
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
}
