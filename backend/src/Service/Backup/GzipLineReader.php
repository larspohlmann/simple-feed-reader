<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Streams the lines of a gzipped text held in memory. The restore reads its
 * upload twice (validate, then load), and php://input yields its bytes only
 * once — so the caller holds the ~4 MiB gzip body as a string and this class
 * inflates it lazily per pass. fgets() does the line assembly in C with an
 * internal carry; never re-split a shared buffer with substr() — that is
 * O(n²) and measured 100× slower (see the spec's appendix).
 */
final readonly class GzipLineReader
{
    /**
     * A gzip stream starts 0x1f 0x8b; anything else would make zlib.inflate
     * produce silent garbage instead of an error, so refuse it up front.
     */
    private const string GZIP_MAGIC = "\x1f\x8b";

    /**
     * @return \Generator<int, string> the lines, each without its trailing newline
     *
     * @throws InvalidBackupException
     */
    public static function lines(string $gzipBytes): \Generator
    {
        if (!str_starts_with($gzipBytes, self::GZIP_MAGIC)) {
            throw new InvalidBackupException('The file is not gzip-compressed.');
        }

        $stream = fopen('php://memory', 'r+b');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open an in-memory stream.');
        }

        try {
            fwrite($stream, $gzipBytes);
            rewind($stream);
            // window 15+32: accept a gzip (or zlib) header, matching gzdecode().
            stream_filter_append($stream, 'zlib.inflate', \STREAM_FILTER_READ, ['window' => 15 + 32]);

            while (false !== ($line = self::readLine($stream))) {
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Valid magic bytes are no promise that the rest of the body inflates: a
     * partially downloaded or bit-flipped file raises "zlib: data error" as a
     * PHP diagnostic, which Symfony's ErrorHandler turns into an
     * ErrorException — not an ApiException, so the listener would answer 500
     * with a stack trace instead of the 422 this refusal is. The handler is
     * installed around the fgets call alone, never across the yield, so it
     * cannot leak into the code consuming the generator.
     *
     * @param resource $stream
     *
     * @throws InvalidBackupException
     */
    private static function readLine($stream): string|false
    {
        set_error_handler(static function (): never {
            throw new InvalidBackupException('The file is not readable as gzip — it is corrupt or truncated.');
        });

        try {
            return fgets($stream);
        } finally {
            restore_error_handler();
        }
    }
}
