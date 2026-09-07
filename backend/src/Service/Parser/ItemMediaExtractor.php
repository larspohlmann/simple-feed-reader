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
        if (self::isMediaNode($child)) {
            return self::fromNode($child, $fallbackDuration);
        }

        return null;
    }

    private static function fromNode(\DOMElement $node, ?int $fallbackDuration): ?ParsedMediaBundle
    {
        $url = self::url($node);
        if ($url === '') {
            return null;
        }

        return match (FeedMediaClassifier::kind($node)) {
            FeedMediaKind::Image => new ParsedMediaBundle([self::image($node, $url)], []),
            FeedMediaKind::Video => new ParsedMediaBundle(
                [self::video($node, $url, null)],
                [self::attachment($node, $url, $fallbackDuration)],
            ),
            FeedMediaKind::Audio, FeedMediaKind::Other => new ParsedMediaBundle(
                [],
                [self::attachment($node, $url, $fallbackDuration)],
            ),
            FeedMediaKind::Unknown => null,
        };
    }

    private static function fromGroup(\DOMElement $group, ?int $fallbackDuration): ?ParsedMediaBundle
    {
        $nodes = self::mediaNodesIn($group);
        $video = self::firstOfKind($nodes, FeedMediaKind::Video);
        if ($video !== null) {
            $poster = self::posterIn($group);

            return new ParsedMediaBundle(
                [self::video($video, self::url($video), $poster)],
                [self::attachment($video, self::url($video), $fallbackDuration)],
            );
        }

        $image = self::widestImage($nodes);
        if ($image !== null) {
            return new ParsedMediaBundle([self::image($image, self::url($image))], []);
        }

        $playable = self::firstPlayable($nodes);

        return $playable === null
            ? null
            : new ParsedMediaBundle([], [self::attachment($playable, self::url($playable), $fallbackDuration)]);
    }

    private static function image(\DOMElement $node, string $url): ParsedMedium
    {
        return new ParsedMedium($url, VisualMediaKind::Image, self::intAttr($node, 'width'), self::intAttr($node, 'height'));
    }

    private static function video(\DOMElement $node, string $url, ?string $previewImageUrl): ParsedMedium
    {
        return new ParsedMedium(
            $url,
            VisualMediaKind::Video,
            self::intAttr($node, 'width'),
            self::intAttr($node, 'height'),
            $previewImageUrl,
        );
    }

    private static function attachment(\DOMElement $node, string $url, ?int $fallbackDuration): ParsedAttachment
    {
        return new ParsedAttachment(
            $url,
            self::nonEmpty($node->getAttribute('type')),
            MediaDuration::seconds($node->getAttribute('duration')) ?? $fallbackDuration,
            self::intAttr($node, 'length') ?? self::intAttr($node, 'fileSize'),
            self::mediaTitle($node),
        );
    }

    /** @param list<\DOMElement> $nodes */
    private static function firstOfKind(array $nodes, FeedMediaKind $kind): ?\DOMElement
    {
        foreach ($nodes as $node) {
            if (FeedMediaClassifier::kind($node) === $kind) {
                return $node;
            }
        }

        return null;
    }

    /** @param list<\DOMElement> $nodes */
    private static function firstPlayable(array $nodes): ?\DOMElement
    {
        return self::firstOfKind($nodes, FeedMediaKind::Audio) ?? self::firstOfKind($nodes, FeedMediaKind::Other);
    }

    /** @param list<\DOMElement> $nodes */
    private static function widestImage(array $nodes): ?\DOMElement
    {
        $widest = null;
        foreach ($nodes as $node) {
            if (FeedMediaClassifier::kind($node) !== FeedMediaKind::Image) {
                continue;
            }
            if ($widest === null || (self::intAttr($node, 'width') ?? 0) > (self::intAttr($widest, 'width') ?? 0)) {
                $widest = $node;
            }
        }

        return $widest;
    }

    /** @return list<\DOMElement> */
    private static function mediaNodesIn(\DOMElement $parent): array
    {
        $nodes = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && self::isMediaNode($child) && self::url($child) !== '') {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    private static function posterIn(\DOMElement $group): ?string
    {
        foreach ($group->childNodes as $child) {
            if (self::isMediaElement($child, 'thumbnail')) {
                /** @var \DOMElement $child */
                return self::nonEmpty(self::url($child));
            }
        }

        return null;
    }

    private static function itunesDuration(\DOMElement $item): ?int
    {
        foreach ($item->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'duration' && $child->namespaceURI === self::ITUNES_NS) {
                return MediaDuration::seconds($child->textContent);
            }
        }

        return null;
    }

    private static function mediaTitle(\DOMElement $node): ?string
    {
        foreach ($node->childNodes as $child) {
            if (self::isMediaElement($child, 'title')) {
                /** @var \DOMElement $child */
                return self::nonEmpty($child->textContent);
            }
        }

        return null;
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

    private static function url(\DOMElement $node): string
    {
        $url = trim($node->getAttribute('url'));

        return $url !== '' ? $url : trim($node->getAttribute('href'));
    }

    private static function intAttr(\DOMElement $node, string $name): ?int
    {
        $value = filter_var(trim($node->getAttribute($name)), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    private static function nonEmpty(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
