<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Evaluates a <source media> query as a desktop window would: the reader shows one rendition in a desktop column.
 * Only px `min-width` and `max-width` are read, as a conjunction, and a condition it cannot read admits the source.
 */
final readonly class DesktopViewport
{
    /** A common laptop window; every publisher's desktop breakpoint seen so far lies below it. */
    private const int WIDTH = 1280;

    private const string MIN_WIDTH = '/\(\s*min-width\s*:\s*(\d+)px\s*\)/i';
    private const string MAX_WIDTH = '/\(\s*max-width\s*:\s*(\d+)px\s*\)/i';

    public function admits(?string $media): bool
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
