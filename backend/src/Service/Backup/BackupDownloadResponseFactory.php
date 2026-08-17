<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Version\ReleaseVersionReader;
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
    public function __construct(
        private ClockInterface $clock,
        private ReleaseVersionReader $versionReader,
    ) {
    }

    /** @param \Generator<int, string> $lines */
    public function stream(string $accountEmail, \Generator $lines): StreamedResponse
    {
        $filename = (new BackupFilename(
            $accountEmail,
            $this->versionReader->read()->version,
            $this->clock->now(),
        ))->value();

        return new StreamedResponse(
            static function () use ($lines): void {
                $gzip = deflate_init(\ZLIB_ENCODING_GZIP);
                if (false === $gzip) {
                    throw new \RuntimeException('Cannot initialise gzip compression.');
                }
                foreach ($lines as $line) {
                    echo deflate_add($gzip, $line . "\n", \ZLIB_NO_FLUSH);
                }
                echo deflate_add($gzip, '', \ZLIB_FINISH);
            },
            headers: [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }
}
