<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Gzip of zero bytes, compressed a megabyte at a time so the test never holds the inflated size. */
final class GzipBomb
{
    private const int CHUNK_BYTES = 1_048_576;

    public static function inflatingTo(int $bytes): string
    {
        $deflate = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);
        \assert($deflate instanceof \DeflateContext);
        $zeros = str_repeat("\0", self::CHUNK_BYTES);
        $bomb = '';
        for ($written = 0; $written < $bytes; $written += self::CHUNK_BYTES) {
            $chunk = substr($zeros, 0, min(self::CHUNK_BYTES, $bytes - $written));
            $bomb .= (string) deflate_add($deflate, $chunk, \ZLIB_NO_FLUSH);
        }

        return $bomb . (string) deflate_add($deflate, '', \ZLIB_FINISH);
    }
}
