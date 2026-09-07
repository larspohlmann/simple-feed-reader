<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Reads a media duration into whole seconds. Feeds state it two ways: a plain
 * integer of seconds (MRSS `duration`) or a colon-separated clock the iTunes
 * podcast namespace uses (`HH:MM:SS` or `MM:SS`). A zero, an empty value, or a
 * non-numeric part means "unknown", never zero seconds.
 */
final class MediaDuration
{
    public static function seconds(?string $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $total = str_contains($value, ':') ? self::fromClock($value) : self::fromInteger($value);

        return $total !== null && $total > 0 ? $total : null;
    }

    private static function fromInteger(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    private static function fromClock(string $value): ?int
    {
        $total = 0;
        foreach (explode(':', $value) as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
            $total = $total * 60 + (int) $part;
        }

        return $total;
    }
}
