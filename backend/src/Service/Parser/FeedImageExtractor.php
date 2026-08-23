<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the image a feed publishes for ITSELF — its logo or banner — as
 * opposed to the image on one of its items, which ItemImageExtractor finds.
 *
 * Atom's <logo> is read; its <icon> deliberately is not. <icon> is
 * favicon-shaped by specification and Feed::$faviconUrl already holds that
 * role, so reading both into one column would make the field mean two things.
 *
 * The returned URL is ready to persist and to put in an <img src>: see
 * usable() for the two ways a URL is rejected.
 */
final class FeedImageExtractor
{
    /** Matches the length of the column this URL is persisted into. */
    private const int URL_MAX = 2048;

    /** RSS 2.0: <channel><image><url>. */
    public static function fromRss2Channel(\DOMElement $channel): ?string
    {
        $image = XmlHelper::childElement($channel, 'image');

        return $image === null ? null : self::usable(XmlHelper::childText($image, 'url'));
    }

    /**
     * RSS 1.0: the channel only points at the image with an rdf:resource
     * attribute; the <image> element carrying the <url> is its SIBLING at the
     * RDF root. Following the reference would mean resolving rdf:about across
     * the document for a value the sibling states outright.
     */
    public static function fromRss1Document(\DOMDocument $document, string $rss1Namespace): ?string
    {
        $root = $document->documentElement;
        if ($root === null) {
            return null;
        }

        // A plain getElementsByTagNameNS('image')->item(0) would find the
        // <channel>'s own <image rdf:resource="…"/> first — it has no <url>
        // child — instead of its sibling at the RDF root. Restricting to
        // direct children of the root is what actually reaches the sibling.
        $image = XmlHelper::childElement($root, 'image', $rss1Namespace);

        return $image === null ? null : self::usable(XmlHelper::childText($image, 'url', $rss1Namespace));
    }

    /** Atom: <feed><logo>. */
    public static function fromAtomFeed(\DOMElement $root, string $atomNamespace): ?string
    {
        return self::usable(XmlHelper::childText($root, 'logo', $atomNamespace));
    }

    /**
     * Rejects a feed-supplied image URL in the two ways it can be unusable,
     * rather than trying to repair it. Mirrors
     * EntryIngestor::persistableImageUrl(), for the same two reasons:
     *
     * - Scheme: the reader SPA is served over https, so an http:// image is
     *   mixed-content-blocked and never renders. A `//host/path` URL is
     *   unambiguous and upgraded; a `data:` URI or a site-relative path has no
     *   scheme to upgrade and no base URL is plumbed this deep to resolve one
     *   against, so it is dropped rather than guessed at. The same check keeps
     *   a `javascript:` value out of the DOM.
     * - Length: a URL over URL_MAX is not truncated. Cutting it at exactly
     *   URL_MAX characters does not shorten a valid URL, it produces a
     *   different, broken one.
     */
    private static function usable(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $absolute = str_starts_with($url, '//') ? 'https:' . $url : $url;

        if (!str_starts_with($absolute, 'https://')) {
            return null;
        }

        return mb_strlen($absolute) > self::URL_MAX ? null : $absolute;
    }
}
