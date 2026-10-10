<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Pass\FeedMediaNode;
use App\Service\Parser\Support\MediaDuration;
use App\Service\Parser\Support\MediaRssSlot;
use App\Service\Parser\Support\XmlHelper;

/**
 * Splits a feed item's declared media into visual media and enclosures, from parsed attributes only. A `<media:group>`
 * is one item in several renditions: it collapses to its video (group thumbnail as poster) or its widest image.
 * The lead image is not prepended here; EntryMediaAssembler does that, so `media[0]` stays the persisted lead.
 */
final readonly class ItemMediaExtractor
{
    public function extract(CoreElement $item): ParsedMediaBundleModel
    {
        $fallbackDuration = self::itunesDuration($item->element);
        $media = [];
        $attachments = [];
        foreach ($item->element->childNodes as $child) {
            $bundle = self::fromChild($item, $child, $fallbackDuration);
            if ($bundle === null) {
                continue;
            }
            array_push($media, ...$bundle->media);
            array_push($attachments, ...$bundle->attachments);
        }

        return new ParsedMediaBundleModel($media, $attachments);
    }

    private static function fromChild(
        CoreElement $item,
        \DOMNode $child,
        ?int $fallbackDuration,
    ): ?ParsedMediaBundleModel {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if (XmlHelper::isElement($child, 'group', XmlHelper::MEDIA_RSS_NAMESPACE)) {
            return self::fromGroup($child, $fallbackDuration);
        }

        return self::isItemMediaNode($item, $child)
            ? self::fromNode(new FeedMediaNode($child), $fallbackDuration)
            : null;
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
    private static function mediaNodesIn(\DOMElement $group): array
    {
        $nodes = [];
        foreach ($group->childNodes as $child) {
            if (!$child instanceof \DOMElement || !MediaRssSlot::isContentOrThumbnail($child)) {
                continue;
            }
            $node = new FeedMediaNode($child);
            if ($node->url() !== '') {
                $nodes[] = $node;
            }
        }

        return $nodes;
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

    private static function isItemMediaNode(CoreElement $item, \DOMElement $node): bool
    {
        if ($item->isCore($node, 'enclosure')) {
            return true;
        }
        if ($item->isCore($node, 'link') && $node->getAttribute('rel') === 'enclosure') {
            return true;
        }

        return MediaRssSlot::isContentOrThumbnail($node);
    }
}
