<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Url\HttpsImageUrl;

/**
 * Finds the image a feed publishes for ITSELF — its logo or banner — as
 * opposed to the image on one of its items, which ItemImageExtractor finds.
 *
 * Atom's <logo> is read; its <icon> deliberately is not. <icon> is
 * favicon-shaped by specification and Feed::$faviconUrl already holds that
 * role, so reading both into one column would make the field mean two things.
 *
 * The returned URL is ready to persist and to put in an <img src>:
 * HttpsImageUrl owns the two ways one is rejected.
 */
final class FeedImageExtractor
{
    /** RSS 2.0: <channel><image><url>. */
    public static function fromRss2Channel(\DOMElement $channel): ?string
    {
        $image = XmlHelper::childElement($channel, 'image');

        return $image === null ? null : HttpsImageUrl::orNull(XmlHelper::childText($image, 'url'));
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

        return $image === null ? null : HttpsImageUrl::orNull(XmlHelper::childText($image, 'url', $rss1Namespace));
    }

    /** Atom: <feed><logo>. */
    public static function fromAtomFeed(\DOMElement $root, string $atomNamespace): ?string
    {
        return HttpsImageUrl::orNull(XmlHelper::childText($root, 'logo', $atomNamespace));
    }
}
