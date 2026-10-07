<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Url\Support\HttpsImageUrl;

/**
 * The image a feed publishes for itself (its logo or banner), ready to persist and to put in an <img src>. Atom's
 * <icon> is not read: it is favicon-shaped by specification, and Feed::$faviconUrl already holds that role.
 */
final class FeedImageExtractor
{
    /** RSS 2.0: <channel><image><url>, else the podcast artwork. An extension's *:image is never the <image>. */
    public static function fromRss2Channel(\DOMElement $channel): ?string
    {
        foreach (XmlHelper::childElements($channel, 'image', null) as $image) {
            $url = $image->namespaceURI === $channel->namespaceURI
                ? HttpsImageUrl::orNull(XmlHelper::childText($image, 'url'))
                : null;
            if ($url !== null) {
                return $url;
            }
        }

        return self::podcastArtwork($channel);
    }

    /**
     * RSS 1.0: the channel only points at the image by rdf:resource; the <image> holding the <url> is its sibling at
     * the RDF root.
     */
    public static function fromRss1Document(\DOMDocument $document, string $rss1Namespace): ?string
    {
        $root = $document->documentElement;
        if ($root === null) {
            return null;
        }

        // Direct children only: a document-wide search finds the channel's url-less <image rdf:resource> first.
        $image = XmlHelper::childElement($root, 'image', $rss1Namespace);

        return $image === null ? null : HttpsImageUrl::orNull(XmlHelper::childText($image, 'url', $rss1Namespace));
    }

    /** Atom: <feed><logo>, else the podcast artwork. */
    public static function fromAtomFeed(\DOMElement $root, string $atomNamespace): ?string
    {
        return HttpsImageUrl::orNull(XmlHelper::childText($root, 'logo', $atomNamespace))
            ?? self::podcastArtwork($root);
    }

    private static function podcastArtwork(\DOMElement $parent): ?string
    {
        $artwork = PodcastArtwork::of($parent);

        return $artwork === null ? null : HttpsImageUrl::orNull($artwork->url);
    }

    private function __construct()
    {
    }
}
