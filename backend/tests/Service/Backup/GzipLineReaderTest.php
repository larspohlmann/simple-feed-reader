<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\GzipLineReader;
use App\Service\Backup\TemporaryBackupFile;
use App\Tests\Support\CorruptGzip;
use App\Tests\Support\TemporaryBackupFixture;
use PHPUnit\Framework\TestCase;

final class GzipLineReaderTest extends TestCase
{
    public function testYieldsEachLineWithoutItsNewline(): void
    {
        $gzip = (string) gzencode("first\nsecond\nthird\n");

        $lines = TemporaryBackupFixture::withBytes(
            $gzip,
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );

        self::assertSame(['first', 'second', 'third'], $lines);
    }

    public function testAFinalLineWithoutNewlineStillArrives(): void
    {
        $gzip = (string) gzencode("first\nlast-no-newline");

        $lines = TemporaryBackupFixture::withBytes(
            $gzip,
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );

        self::assertSame(['first', 'last-no-newline'], $lines);
    }

    public function testALineLongerThanAnyInternalBufferSurvivesIntact(): void
    {
        $long = str_repeat('x', 2_000_000);
        $gzip = (string) gzencode($long . "\nshort\n");

        $lines = TemporaryBackupFixture::withBytes(
            $gzip,
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );

        self::assertSame([$long, 'short'], $lines);
    }

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        TemporaryBackupFixture::withBytes(
            'this is not gzip',
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );
    }

    /**
     * A partially downloaded file keeps its magic bytes, so the header guard
     * waves it through and zlib only fails deep inside the inflate — the most
     * likely real-world corruption of a 4 MiB body.
     */
    public function testABodyWithValidMagicButCorruptPayloadIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        TemporaryBackupFixture::withBytes(
            CorruptGzip::bytes(),
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        TemporaryBackupFixture::withBytes(
            '',
            static fn (TemporaryBackupFile $file): array => iterator_to_array(
                GzipLineReader::lines($file->open()),
                false,
            ),
        );
    }
}
