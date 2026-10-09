<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Parser\Pass\FeedMediaNode;
use App\Service\Parser\Support\MediaDuration;
use App\Service\Parser\Support\XmlHelper;

/**
 * Splits a feed item's declared media into visual media and enclosures, from parsed attributes only. A `<media:group>`
 * is one item in several renditions: it collapses to its video (group thumbnail as poster) or its widest image.
 * The lead image is not prepended here; EntryMediaAssembler does that, so `media[0]` stays the persisted lead.
 */
final readonly class ItemMediaExtractor
{
    public function extract(\DOMElement $item): ParsedMediaBundleModel
    {
        $fallbackDuration = self::itunesDuration($item);
        $media = [];
        $attachments = [];
        foreach ($item->childNodes as $child) {
            $bundle = self::fromChild($child, $fallbackDuration);
            if ($bundle === null) {
                continue;
            }
            array_push($media, ...$bundle->media);
            array_push($attachments, ...$bundle->attachments);
        }

        return new ParsedMediaBundleModel($media, $attachments);
    }

    private static function fromChild(\DOMNode $child, ?int $fallbackDuration): ?ParsedMediaBundleModel
    {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if (XmlHelper::isElement($child, 'group', XmlHelper::MEDIA_RSS_NAMESPACE)) {
            return self::fromGroup($child, $fallbackDuration);
        }
        $node = self::mediaNode($child);

        return $node === null ? null : self::fromNode($node, $fallbackDuration);
    }

    private static function fromNode(FeedMediaNode $node, ?int $fallbackDuration): ?ParsedMediaBundleModel
    {
        return match ($node->kind()) {
            FeedMediaKind::Image => new ParsedMediaBundleModel([$node->toImage()], []),
            FeedMediaKind::Video => new ParsedMediaBundleModel(
                [$node->toVideo(null)],
                [$node->toAttachment($fallbackDuration)],
            ),
            FeedMediaKind::Audio, FeedMediaKind::Other => new ParsedMediaBundleModel(
                [],
                [$node->toAttachment($fallbackDuration)],
            ),
            FeedMediaKind::Unknown => null,
        };
    }

    private static function fromGroup(\DOMElement $group, ?int $fallbackDuration): ?ParsedMediaBundleModel
    {
        $nodes = self::mediaNodesIn($group);

        $video = self::firstOfKind($nodes, FeedMediaKind::Video);
        if ($video !== null) {
            return new ParsedMediaBundleModel(
                [$video->toVideo(self::posterIn($group))],
                [$video->toAttachment($fallbackDuration)],
            );
        }

        $image = self::widestImage($nodes);
        if ($image !== null) {
            return new ParsedMediaBundleModel([$image->toImage()], []);
        }

        $playable = self::firstPlayable($nodes);

        return $playable === null
            ? null
            : new ParsedMediaBundleModel([], [$playable->toAttachment($fallbackDuration)]);
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
        $thumbnail = XmlHelper::childElement($group, 'thumbnail', XmlHelper::MEDIA_RSS_NAMESPACE);
        $url = $thumbnail === null ? '' : trim($thumbnail->getAttribute('url'));

        return $url !== '' ? $url : null;
    }

    private static function itunesDuration(\DOMElement $item): ?int
    {
        $duration = XmlHelper::childElement($item, 'duration', XmlHelper::ITUNES_NAMESPACE);

        return $duration === null ? null : MediaDuration::seconds($duration->textContent);
    }

    private static function isMediaNode(\DOMElement $node): bool
    {
        if ($node->localName === 'enclosure') {
            return true;
        }
        if ($node->localName === 'link' && $node->getAttribute('rel') === 'enclosure') {
            return true;
        }

        return XmlHelper::isElement($node, 'content', XmlHelper::MEDIA_RSS_NAMESPACE)
            || XmlHelper::isElement($node, 'thumbnail', XmlHelper::MEDIA_RSS_NAMESPACE);
    }
}
