<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Each restore pass receives a fresh file handle and retains only the current
 * inflated line in PHP memory.
 */
final readonly class GzipLineReader
{
    /**
     * A gzip stream starts 0x1f 0x8b; anything else would make zlib.inflate
     * produce silent garbage instead of an error, so refuse it up front.
     */
    private const string GZIP_MAGIC = "\x1f\x8b";

    /**
     * @param resource $gzipStream
     * @return \Generator<int, string>
     *
     * @throws InvalidBackupException
     */
    public static function lines($gzipStream): \Generator
    {
        try {
            if (fread($gzipStream, 2) !== self::GZIP_MAGIC) {
                throw new InvalidBackupException('The file is not gzip-compressed.');
            }

            if (!rewind($gzipStream)) {
                throw new \RuntimeException('Cannot rewind the stored backup upload.');
            }

            if (
                stream_filter_append(
                    $gzipStream,
                    'zlib.inflate',
                    \STREAM_FILTER_READ,
                    ['window' => 15 + 32],
                ) === false
            ) {
                throw new \RuntimeException('Cannot inflate the stored backup upload.');
            }

            while (false !== ($line = self::readLine($gzipStream))) {
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($gzipStream);
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
