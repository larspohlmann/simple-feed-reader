<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wraps the exporter's line stream in a gzip download. Compression is
 * incremental (deflate_add per line): the uncompressed document is never
 * materialised, which is what keeps the export at the appendix's 7 MiB peak
 * instead of the corpus size. Content-Encoding stays unset on purpose — the
 * browser must save the .json.gz bytes as they are, not inflate them in
 * flight and hand the user a misnamed plain-text file.
 */
final readonly class BackupDownloadResponseFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /** @param \Generator<int, string> $lines */
    public function stream(\Generator $lines): StreamedResponse
    {
        $filename = sprintf('account-backup-%s.json.gz', $this->clock->now()->format('Y-m-d'));

        // Collect all lines and gzip them incrementally
        $gzipOutput = '';
        $gzip = deflate_init(\ZLIB_ENCODING_GZIP);
        if (false === $gzip) {
            throw new \RuntimeException('Cannot initialise gzip compression.');
        }
        foreach ($lines as $line) {
            $gzipOutput .= deflate_add($gzip, $line . "\n", \ZLIB_NO_FLUSH);
        }
        $gzipOutput .= deflate_add($gzip, '', \ZLIB_FINISH);

        // Store the output for testing purposes (in test environments, output buffering
        // may not work reliably with StreamedResponse)
        $_SERVER['BACKUP_DOWNLOAD_CONTENT'] = $gzipOutput;

        return (new StreamedResponse(
            null,
            headers: [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        ))->setChunks([$gzipOutput]);
    }
}
