<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\BackupCompressionException;
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
     * Read a line one buffer at a time rather than pre-allocating the whole
     * line bound per fgets call, so a normal small line costs one small
     * buffer and only a genuine bomb ever grows near the bound.
     */
    public const int READ_CHUNK_BYTES = 1_048_576;

    /**
     * @param int $maxLineBytes a line still without its newline past this is a
     *                          decompression bomb, not data: refusing it keeps one giant line off memory_limit
     *
     * @return \Generator<int, string> the lines, each without its trailing newline
     *
     * @throws InvalidBackupException
     * @throws BackupCompressionException
     */
    public static function lines(string $gzipBytes, int $maxLineBytes): \Generator
    {
        if (!str_starts_with($gzipBytes, self::GZIP_MAGIC)) {
            throw new InvalidBackupException('The file is not gzip-compressed.');
        }

        $stream = fopen('php://memory', 'r+b');
        if (false === $stream) {
            throw new BackupCompressionException('Cannot open an in-memory stream.');
        }

        try {
            fwrite($stream, $gzipBytes);
            rewind($stream);
            // window 15+32: accept a gzip (or zlib) header, matching gzdecode().
            stream_filter_append($stream, 'zlib.inflate', \STREAM_FILTER_READ, ['window' => 15 + 32]);

            while (false !== ($line = self::readLine($stream, $maxLineBytes))) {
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Valid magic bytes promise nothing about the rest: a truncated or bit-flipped body raises a zlib diagnostic,
     * an ErrorException that would surface as an opaque 500 instead of this refusal. The handler wraps the fgets
     * call alone, never the yield, so it cannot leak into the generator's consumer.
     *
     * @param resource $stream
     *
     * @throws InvalidBackupException
     */
    private static function readLine($stream, int $maxLineBytes): string|false
    {
        set_error_handler(static function (): never {
            throw new InvalidBackupException('The file is not readable as gzip — it is corrupt or truncated.');
        });

        try {
            return self::readBoundedLine($stream, $maxLineBytes);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param resource $stream
     *
     * @throws InvalidBackupException
     */
    private static function readBoundedLine($stream, int $maxLineBytes): string|false
    {
        $line = '';
        while (true) {
            $chunk = fgets($stream, self::READ_CHUNK_BYTES);
            if (false === $chunk) {
                return '' === $line ? false : $line;
            }

            $line .= $chunk;
            if (str_ends_with($chunk, "\n")) {
                return $line;
            }

            if (\strlen($line) > $maxLineBytes) {
                throw new InvalidBackupException(
                    sprintf('A backup line is larger than %d bytes.', $maxLineBytes),
                );
            }
        }
    }
}
