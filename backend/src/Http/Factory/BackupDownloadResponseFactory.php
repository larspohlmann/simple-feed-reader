<?php

declare(strict_types=1);

namespace App\Http\Factory;

use App\Service\Backup\Model\BackupFilenameModel;
use App\Service\Backup\Model\BackupPartModel;
use App\Service\Version\ReleaseVersionReader\ReleaseVersionReaderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * Streams the exporter's backup parts as a stored zip: each member is
 * already gzip-compressed, so Content-Encoding stays unset on purpose.
 */
final readonly class BackupDownloadResponseFactory
{
    public function __construct(
        private ClockInterface $clock,
        private ReleaseVersionReaderInterface $versionReader,
    ) {
    }

    /** @param \Generator<int, BackupPartModel> $parts */
    public function stream(string $accountEmail, \Generator $parts): StreamedResponse
    {
        $filename = (new BackupFilenameModel(
            $accountEmail,
            $this->versionReader->read()->version,
            $this->clock->now(),
        ))->value();

        return new StreamedResponse(
            static function () use ($parts): void {
                $zip = new ZipStream(
                    sendHttpHeaders: false,
                    defaultCompressionMethod: CompressionMethod::STORE,
                    defaultEnableZeroHeader: false,
                );
                foreach ($parts as $part) {
                    $zip->addFile(fileName: $part->memberName, data: $part->gzipBytes);
                }
                $zip->finish();
            },
            headers: [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }
}
