<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the best image attached to a feed item. Callers combine the sources in
 * the precedence their format prefers (Media RSS, then a format's enclosure,
 * then an inline <img>).
 *
 * Within Media RSS the WIDEST declared variant wins, not the first. Feeds
 * routinely ship a ladder of sizes — the Guardian publishes 140/460/700 in
 * ascending order, so "first" would persist a thumbnail too small to feature.
 * An element that declares no width loses to any element that declares one;
 * when nothing declares a width, document order decides.
 *
 * URLs are returned verbatim — callers that need an absolute URL resolve it
 * themselves.
 */
final class ItemImageExtractor
{
    private const string MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /** Media RSS image, searching <media:group> when nothing is attached directly. */
    public static function fromMedia(\DOMElement $item): ?ParsedImage
    {
        $candidates = self::mediaCandidatesIn($item);

        foreach ($item->childNodes as $child) {
            if (self::isMediaElement($child, 'group')) {
                /** @var \DOMElement $child */
                $candidates = [...$candidates, ...self::mediaCandidatesIn($child)];
            }
        }

        return self::widest($candidates);
    }

    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public static function fromRssEnclosure(\DOMElement $item): ?ParsedImage
    {
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'enclosure') {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '') {
                return self::imageFrom($child, $url);
            }
        }

        return null;
    }

    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public static function fromAtomEnclosure(\DOMElement $entry, string $ns): ?ParsedImage
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== $ns
                || $child->getAttribute('rel') !== 'enclosure'
            ) {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href !== '') {
                return self::imageFrom($child, $href);
            }
        }

        return null;
    }

    /** First <img src="…"> in a fragment of HTML. Dimensions are never trusted here. */
    public static function fromHtml(?string $html): ?ParsedImage
    {
        if ($html === null || $html === '') {
            return null;
        }
        if (preg_match('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $matches) !== 1) {
            return null;
        }
        $src = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5));

        return $src === '' ? null : new ParsedImage($src);
    }

    /** @return list<ParsedImage> */
    private static function mediaCandidatesIn(\DOMElement $parent): array
    {
        $candidates = [];
        foreach ($parent->childNodes as $child) {
            if (!self::isMediaElement($child, 'thumbnail') && !self::isMediaElement($child, 'content')) {
                continue;
            }
            /** @var \DOMElement $child */
            $url = trim($child->getAttribute('url'));
            if ($url === '' || !self::isImageElement($child)) {
                continue;
            }
            $candidates[] = self::imageFrom($child, $url);
        }

        return $candidates;
    }

    /**
     * <media:thumbnail> is an image by definition. <media:content> is only an
     * image when it says so — the same element carries audio and video.
     */
    private static function isImageElement(\DOMElement $element): bool
    {
        if ($element->localName === 'thumbnail') {
            return true;
        }
        $medium = strtolower($element->getAttribute('medium'));
        $type = strtolower($element->getAttribute('type'));

        return $medium === 'image'
            || str_starts_with($type, 'image/')
            || ($medium === '' && $type === '');
    }

    private static function isMediaElement(\DOMNode $node, string $localName): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === self::MEDIA_NS;
    }

    private static function imageFrom(\DOMElement $element, string $url): ParsedImage
    {
        return new ParsedImage(
            $url,
            self::positiveInt($element->getAttribute('width')),
            self::positiveInt($element->getAttribute('height')),
        );
    }

    private static function positiveInt(string $raw): ?int
    {
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    /** @param list<ParsedImage> $candidates */
    private static function widest(array $candidates): ?ParsedImage
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null) {
                $best = $candidate;
                continue;
            }
            if (($candidate->width ?? 0) > ($best->width ?? 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
