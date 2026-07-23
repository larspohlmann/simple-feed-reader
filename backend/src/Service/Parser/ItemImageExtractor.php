<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the first usable image URL attached to a feed item. Callers combine the
 * sources in the precedence their format prefers (Media RSS, then a format's
 * enclosure, then an inline <img>). URLs are returned verbatim — callers that
 * need an absolute URL resolve it themselves; the preview only needs presence.
 */
final class ItemImageExtractor
{
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /** Media RSS image: <media:thumbnail> preferred, else an image <media:content>. */
    public static function fromMedia(\DOMElement $item): ?string
    {
        $thumb = self::mediaUrl($item, 'thumbnail');
        if ($thumb !== null) {
            return $thumb;
        }

        foreach ($item->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'content'
                || $child->namespaceURI !== self::MEDIA_NS
            ) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            $medium = strtolower($child->getAttribute('medium'));
            $type = strtolower($child->getAttribute('type'));
            if ($url !== '' && ($medium === 'image' || str_starts_with($type, 'image/'))) {
                return $url;
            }
        }

        return null;
    }

    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public static function fromRssEnclosure(\DOMElement $item): ?string
    {
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'enclosure') {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '' && str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                return $url;
            }
        }

        return null;
    }

    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public static function fromAtomEnclosure(\DOMElement $entry, string $ns): ?string
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
            $href = trim($child->getAttribute('href'));
            if ($href !== '' && str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                return $href;
            }
        }

        return null;
    }

    /** First <img src="…"> in a fragment of HTML. */
    public static function fromHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        if (preg_match('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $m) !== 1) {
            return null;
        }
        $src = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));

        return $src === '' ? null : $src;
    }

    private static function mediaUrl(\DOMElement $item, string $localName): ?string
    {
        foreach ($item->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === self::MEDIA_NS
            ) {
                $url = trim($child->getAttribute('url'));
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }
}
