<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Pass\CoreElement;
use App\Service\Url\Support\HttpsImageUrl;

/**
 * The image a feed publishes for itself (its logo or banner), ready to persist and to put in an <img src>. Atom's
 * <icon> is not read: it is favicon-shaped by specification, and Feed::$faviconUrl already holds that role.
 */
final class FeedImageExtractor
{
    /** RSS 2.0: <channel><image><url>, else the podcast artwork. An extension's *:image is never the <image>. */
    public static function fromRss2Channel(CoreElement $channel): ?string
    {
        foreach ($channel->children('image') as $image) {
            $url = HttpsImageUrl::orNull($channel->at($image)->text('url'));
            if ($url !== null) {
                return $url;
            }
        }

        return self::podcastArtwork($channel->element);
    }

    /**
     * RSS 1.0: the channel only points at the image by rdf:resource; the <image> holding the <url> is its sibling at
     * the RDF root.
     */
    public static function fromRss1Channel(CoreElement $channel): ?string
    {
        $root = $channel->element->ownerDocument?->documentElement;
        if ($root === null) {
            return null;
        }

        // Direct children only: a document-wide search finds the channel's url-less <image rdf:resource> first.
        $image = $channel->at($root)->child('image');

        return $image === null ? null : HttpsImageUrl::orNull($channel->at($image)->text('url'));
    }

    /** Atom: <feed><logo>, else the podcast artwork. */
    public static function fromAtomFeed(CoreElement $feed): ?string
    {
        return HttpsImageUrl::orNull($feed->text('logo')) ?? self::podcastArtwork($feed->element);
    }

    private static function podcastArtwork(\DOMElement $parent): ?string
    {
        return HttpsImageUrl::orNull(PodcastArtwork::of($parent)?->url);
    }

    private function __construct()
    {
    }
}
