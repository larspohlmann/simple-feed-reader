<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A real gzip with one deflate byte inverted: the magic bytes still match, so only the inflate can refuse it, just as
 * with a partially downloaded backup.
 */
final class CorruptGzip
{
    public static function bytes(): string
    {
        $gzip = (string) gzencode(str_repeat("a backup line\n", 500));
        $middle = intdiv(\strlen($gzip), 2);
        $gzip[$middle] = \chr(\ord($gzip[$middle]) ^ 0xFF);

        return $gzip;
    }
}
