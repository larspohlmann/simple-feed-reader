<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\GzipLineReader;
use App\Tests\Support\CorruptGzip;
use PHPUnit\Framework\TestCase;

final class GzipLineReaderTest extends TestCase
{
    private const int ROOMY_BOUND = 4_194_304;

    /** The most one fgets() read returns; the bound is only checked between reads. */
    private const int FULL_READ = GzipLineReader::READ_CHUNK_BYTES - 1;

    public function testYieldsEachLineWithoutItsNewline(): void
    {
        $gzip = (string) gzencode("first\nsecond\nthird\n");

        self::assertSame(['first', 'second', 'third'], self::linesOf($gzip, self::ROOMY_BOUND));
    }

    public function testAFinalLineWithoutNewlineStillArrives(): void
    {
        $gzip = (string) gzencode("first\nlast-no-newline");

        self::assertSame(['first', 'last-no-newline'], self::linesOf($gzip, self::ROOMY_BOUND));
    }

    public function testALineLongerThanAnyInternalBufferSurvivesIntact(): void
    {
        $long = str_repeat('x', 2_000_000);
        $gzip = (string) gzencode($long . "\nshort\n");

        self::assertSame([$long, 'short'], self::linesOf($gzip, self::ROOMY_BOUND));
    }

    public function testALineThatOutgrowsTheBoundIsRefused(): void
    {
        $gzip = (string) gzencode(str_repeat('x', 3 * self::FULL_READ) . "\nshort\n");

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('A backup line is larger than 1500000 bytes.');

        self::linesOf($gzip, 1_500_000);
    }

    public function testALineExactlyAtTheBoundIsAccepted(): void
    {
        $atTheBound = str_repeat('x', self::FULL_READ);
        $gzip = (string) gzencode($atTheBound . "\nshort\n");

        self::assertSame([$atTheBound, 'short'], self::linesOf($gzip, self::FULL_READ));
    }

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        self::linesOf('this is not gzip', self::ROOMY_BOUND);
    }

    /**
     * A partially downloaded file keeps its magic bytes, so the header guard
     * waves it through and zlib only fails deep inside the inflate — the most
     * likely real-world corruption of a 4 MiB body.
     */
    public function testABodyWithValidMagicButCorruptPayloadIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        self::linesOf(CorruptGzip::bytes(), self::ROOMY_BOUND);
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        self::linesOf('', self::ROOMY_BOUND);
    }

    /** @return list<string> */
    private static function linesOf(string $gzip, int $maxLineBytes): array
    {
        return iterator_to_array(GzipLineReader::lines($gzip, $maxLineBytes), false);
    }
}
