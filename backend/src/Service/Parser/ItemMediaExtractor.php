<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Enumerates the media a feed item declares into the two lists the entry keeps:
 * visual media to show and enclosures to play or download. It reads only
 * attributes already present in the parsed feed — no fetch, no decode.
 *
 * A `<media:group>` is one logical item shipped as several renditions, so it
 * collapses to a single medium: the video it contains (with the group's
 * thumbnail as poster), or the widest image when it holds only images.
 * Top-level nodes each stand on their own. The lead image is NOT prepended here;
 * the ingest path merges it so `media[0]` stays the persisted lead.
 */
final class ItemMediaExtractor
{
    private const string MEDIA_NS = 'http://search.yahoo.com/mrss/';
    private const string ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    public static function extract(\DOMElement $item): ParsedMediaBundle
    {
        $fallbackDuration = self::itunesDuration($item);
        $media = [];
        $attachments = [];
        foreach ($item->childNodes as $child) {
            $bundle = self::fromChild($child, $fallbackDuration);
            if ($bundle === null) {
                continue;
            }
            $media = [...$media, ...$bundle->media];
            $attachments = [...$attachments, ...$bundle->attachments];
        }

        return new ParsedMediaBundle($media, $attachments);
    }

    private static function fromChild(\DOMNode $child, ?int $fallbackDuration): ?ParsedMediaBundle
    {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if (self::isMediaElement($child, 'group')) {
            return self::fromGroup($child, $fallbackDuration);
        }
        $node = self::mediaNode($child);

        return $node === null ? null : self::fromNode($node, $fallbackDuration);
    }

    private static function fromNode(FeedMediaNode $node, ?int $fallbackDuration): ?ParsedMediaBundle
    {
        return match ($node->kind()) {
            FeedMediaKind::Image => new ParsedMediaBundle([$node->toImage()], []),
            FeedMediaKind::Video => new ParsedMediaBundle(
                [$node->toVideo(null)],
                [$node->toAttachment($fallbackDuration)],
            ),
            FeedMediaKind::Audio, FeedMediaKind::Other => new ParsedMediaBundle(
                [],
                [$node->toAttachment($fallbackDuration)],
            ),
            FeedMediaKind::Unknown => null,
        };
    }

    private static function fromGroup(\DOMElement $group, ?int $fallbackDuration): ?ParsedMediaBundle
    {
        $nodes = self::mediaNodesIn($group);

        $video = self::firstOfKind($nodes, FeedMediaKind::Video);
        if ($video !== null) {
            return new ParsedMediaBundle(
                [$video->toVideo(self::posterIn($group))],
                [$video->toAttachment($fallbackDuration)],
            );
        }

        $image = self::widestImage($nodes);
        if ($image !== null) {
            return new ParsedMediaBundle([$image->toImage()], []);
        }

        $playable = self::firstPlayable($nodes);

        return $playable === null
            ? null
            : new ParsedMediaBundle([], [$playable->toAttachment($fallbackDuration)]);
    }

    /**
     * @param list<FeedMediaNode> $nodes
     */
    private static function firstOfKind(array $nodes, FeedMediaKind $kind): ?FeedMediaNode
    {
        foreach ($nodes as $node) {
            if ($node->kind() === $kind) {
                return $node;
            }
        }

        return null;
    }

    /** @param list<FeedMediaNode> $nodes */
    private static function firstPlayable(array $nodes): ?FeedMediaNode
    {
        return self::firstOfKind($nodes, FeedMediaKind::Audio) ?? self::firstOfKind($nodes, FeedMediaKind::Other);
    }

    /** @param list<FeedMediaNode> $nodes */
    private static function widestImage(array $nodes): ?FeedMediaNode
    {
        $widest = null;
        foreach ($nodes as $node) {
            if ($node->kind() !== FeedMediaKind::Image) {
                continue;
            }
            if ($widest === null || ($node->width() ?? 0) > ($widest->width() ?? 0)) {
                $widest = $node;
            }
        }

        return $widest;
    }

    /** @return list<FeedMediaNode> */
    private static function mediaNodesIn(\DOMElement $parent): array
    {
        $nodes = [];
        foreach ($parent->childNodes as $child) {
            $node = $child instanceof \DOMElement ? self::mediaNode($child) : null;
            if ($node !== null && $node->url() !== '') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function mediaNode(\DOMElement $element): ?FeedMediaNode
    {
        return self::isMediaNode($element) ? new FeedMediaNode($element) : null;
    }

    private static function posterIn(\DOMElement $group): ?string
    {
        foreach ($group->childNodes as $child) {
            if (self::isMediaElement($child, 'thumbnail')) {
                /** @var \DOMElement $child */
                $url = trim($child->getAttribute('url'));

                return $url !== '' ? $url : null;
            }
        }

        return null;
    }

    private static function itunesDuration(\DOMElement $item): ?int
    {
        foreach ($item->childNodes as $child) {
            if (self::isItunesDuration($child)) {
                return MediaDuration::seconds($child->textContent);
            }
        }

        return null;
    }

    private static function isItunesDuration(\DOMNode $node): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === 'duration'
            && $node->namespaceURI === self::ITUNES_NS;
    }

    private static function isMediaNode(\DOMElement $node): bool
    {
        if ($node->localName === 'enclosure') {
            return true;
        }
        if ($node->localName === 'link' && $node->getAttribute('rel') === 'enclosure') {
            return true;
        }

        return self::isMediaElement($node, 'content') || self::isMediaElement($node, 'thumbnail');
    }

    private static function isMediaElement(\DOMNode $node, string $localName): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === self::MEDIA_NS;
    }
}
