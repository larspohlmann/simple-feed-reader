<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A real gzip whose deflate payload has one byte inverted. The two magic
 * bytes still match, so nothing before the inflate itself can refuse it —
 * which is exactly what a partially downloaded backup looks like, and the
 * shape both GzipLineReader and the restore endpoint have to answer for.
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
