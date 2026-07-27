<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Decides whether a Media RSS element points at an image.
 *
 * <media:thumbnail> is an image by definition. <media:content> carries audio and
 * video too, so its kind must be established. An explicit medium or type is
 * authoritative. When BOTH are absent — as on the Guardian, whose bare
 * <media:content width=.. url=..jpg> is the motivating multi-variant feed — the
 * URL's file extension is the only signal left, and it is what separates that
 * image from a podcast's bare <media:content url=..mp3>.
 */
final class MediaImageClassifier
{
    /** @var list<string> */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'];

    public static function isImage(\DOMElement $element): bool
    {
        if ($element->localName === 'thumbnail') {
            return true;
        }

        $medium = strtolower($element->getAttribute('medium'));
        if ($medium === 'image') {
            return true;
        }
        if ($medium === 'audio' || $medium === 'video') {
            return false;
        }

        $type = strtolower($element->getAttribute('type'));
        if (str_starts_with($type, 'image/')) {
            return true;
        }
        if (str_starts_with($type, 'audio/') || str_starts_with($type, 'video/')) {
            return false;
        }

        return self::hasImageExtension($element->getAttribute('url'));
    }

    private static function hasImageExtension(string $url): bool
    {
        $path = strtolower((string) parse_url(trim($url), \PHP_URL_PATH));

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            if (str_ends_with($path, '.' . $extension)) {
                return true;
            }
        }

        return false;
    }
}
