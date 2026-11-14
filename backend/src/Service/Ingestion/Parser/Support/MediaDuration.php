<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

/**
 * A media duration in whole seconds, from plain seconds (MRSS) or an iTunes `HH:MM:SS`/`MM:SS` clock. Zero, empty or
 * non-numeric means unknown (null), never zero seconds.
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

    private function __construct()
    {
    }
}
