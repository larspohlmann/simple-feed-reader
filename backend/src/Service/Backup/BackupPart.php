<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * One gzip member of a split backup: its archive file name and the already
 * gzip-compressed NDJSON bytes it holds.
 */
final readonly class BackupPart
{
    public function __construct(public string $memberName, public string $gzipBytes)
    {
    }

    public static function foundation(string $gzipBytes): self
    {
        return new self('000-foundation.ndjson.gz', $gzipBytes);
    }

    public static function entries(int $part, string $gzipBytes): self
    {
        return new self(sprintf('%03d-entries.ndjson.gz', $part), $gzipBytes);
    }

    public static function gzip(string $ndjson): string
    {
        $bytes = gzencode($ndjson);

        return false !== $bytes ? $bytes : throw new \RuntimeException('Could not gzip-encode a backup part.');
    }
}
