<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\GzipLineReader;
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

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines('this is not gzip'), false);
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines(''), false);
    }
}
