<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Stands in for the viewport a <source media> query is evaluated against. The
 * reader shows one rendition in a desktop column, so it reads like a desktop
 * window: a source scoped to narrower viewports is a mobile art-direction crop
 * and no candidate (zeit lists those first, entry 497686), and one scoped to
 * wider viewports is a crop for a screen the reader is not.
 *
 * Only `min-width` and `max-width` in px are read. A query counts as the
 * conjunction of its conditions, and a condition the reader cannot evaluate
 * admits the source.
 */
final readonly class DesktopViewport
{
    /** A common laptop window; every publisher's desktop breakpoint seen so far lies below it. */
    private const int WIDTH = 1280;

    private const string MIN_WIDTH = '/\(\s*min-width\s*:\s*(\d+)px\s*\)/i';
    private const string MAX_WIDTH = '/\(\s*max-width\s*:\s*(\d+)px\s*\)/i';

    public static function admits(?string $media): bool
    {
        return self::narrowestMaxWidth($media) >= self::WIDTH && self::widestMinWidth($media) <= self::WIDTH;
    }

    private static function narrowestMaxWidth(?string $media): int
    {
        $bounds = self::bounds(self::MAX_WIDTH, $media);

        return $bounds === [] ? PHP_INT_MAX : min($bounds);
    }

    private static function widestMinWidth(?string $media): int
    {
        $bounds = self::bounds(self::MIN_WIDTH, $media);

        return $bounds === [] ? 0 : max($bounds);
    }

    /** @return list<int> */
    private static function bounds(string $pattern, ?string $media): array
    {
        preg_match_all($pattern, $media ?? '', $matches);

        return array_map(intval(...), $matches[1]);
    }
}
