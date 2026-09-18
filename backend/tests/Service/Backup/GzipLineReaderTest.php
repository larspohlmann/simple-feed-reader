<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\GzipLineReader;
use App\Tests\Support\CorruptGzip;
use PHPUnit\Framework\TestCase;

final class GzipLineReaderTest extends TestCase
{
    public function testYieldsEachLineWithoutItsNewline(): void
    {
        $gzip = (string) gzencode("first\nsecond\nthird\n");

        self::assertSame(['first', 'second', 'third'], iterator_to_array(GzipLineReader::lines($gzip), false));
    }

    public function testAFinalLineWithoutNewlineStillArrives(): void
    {
        $gzip = (string) gzencode("first\nlast-no-newline");

        self::assertSame(['first', 'last-no-newline'], iterator_to_array(GzipLineReader::lines($gzip), false));
    }

    public function testALineLongerThanAnyInternalBufferSurvivesIntact(): void
    {
        $long = str_repeat('x', 2_000_000);
        $gzip = (string) gzencode($long . "\nshort\n");

        $lines = iterator_to_array(GzipLineReader::lines($gzip), false);

        self::assertSame([$long, 'short'], $lines);
    }

    public function testASingleLineLargerThanTheCapIsRefused(): void
    {
        $gzip = (string) gzencode(str_repeat('x', GzipLineReader::MAX_LINE_BYTES + 2_000_000) . "\nshort\n");

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/larger than/');

        foreach (GzipLineReader::lines($gzip) as $ignored) {
            unset($ignored);
        }
    }

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines('this is not gzip'), false);
    }

    /**
     * A partially downloaded file keeps its magic bytes, so the header guard
     * waves it through and zlib only fails deep inside the inflate — the most
     * likely real-world corruption of a 4 MiB body.
     */
    public function testABodyWithValidMagicButCorruptPayloadIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines(CorruptGzip::bytes()), false);
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines(''), false);
    }
}
