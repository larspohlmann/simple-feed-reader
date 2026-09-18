<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupDownloadResponseFactory;
use App\Service\Backup\BackupPart;
use App\Service\Version\ReleaseVersion;
use App\Service\Version\ReleaseVersionReader;
use App\Tests\Support\TickingClock;
use PHPUnit\Framework\TestCase;

final class BackupDownloadResponseFactoryTest extends TestCase
{
    public function testItStreamsEveryPartAsAStoredZipMember(): void
    {
        $parts = (static function (): \Generator {
            yield BackupPart::entries(1, (string) gzencode("entries\n"));
            yield BackupPart::foundation((string) gzencode("foundation\n"));
        })();

        $response = $this->factory()->stream('reader@example.com', $parts);
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'backup-zip');
        file_put_contents($path, (string) ob_get_clean());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame(2, $zip->numFiles);
        self::assertSame("foundation\n", gzdecode((string) $zip->getFromName('000-foundation.ndjson.gz')));
        $entriesPartStat = $zip->statName('001-entries.ndjson.gz');
        self::assertIsArray($entriesPartStat);
        self::assertSame(\ZipArchive::CM_STORE, $entriesPartStat['comp_method']);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringEndsWith('.zip"', (string) $response->headers->get('Content-Disposition'));
        $zip->close();
        unlink($path);
    }

    private function factory(): BackupDownloadResponseFactory
    {
        return new BackupDownloadResponseFactory(
            new TickingClock(new \DateTimeImmutable('2026-08-17T09:30:00Z'), 0),
            new class implements ReleaseVersionReader {
                public function read(): ReleaseVersion
                {
                    return ReleaseVersion::development();
                }
            },
        );
    }
}
