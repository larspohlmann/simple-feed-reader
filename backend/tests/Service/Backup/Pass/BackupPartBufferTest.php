<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Pass;

use App\Service\Backup\Pass\BackupPartBuffer;
use PHPUnit\Framework\TestCase;

final class BackupPartBufferTest extends TestCase
{
    public function testDrainWritesHeaderEntriesThenStatesThenTheFooter(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add('{"kind":"entry","n":1}', '{"kind":"entryState","n":1}');
        $buffer->add('{"kind":"entry","n":2}', null);

        self::assertSame(2, $buffer->entryCount());
        self::assertSame(1, $buffer->entryStateCount());

        $gzipBytes = $buffer->drain('{"kind":"header"}', '{"kind":"footer"}');
        $lines = explode("\n", rtrim((string) gzdecode($gzipBytes), "\n"));

        self::assertSame([
            '{"kind":"header"}',
            '{"kind":"entry","n":1}',
            '{"kind":"entry","n":2}',
            '{"kind":"entryState","n":1}',
            '{"kind":"footer"}',
        ], $lines);
        self::assertTrue($buffer->isEmpty());
    }

    public function testDrainEndsThePartWithANewlineAfterTheFooter(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add('{"kind":"entry"}', null);

        $gzipBytes = $buffer->drain('{"kind":"header"}', '{"kind":"footer"}');

        self::assertSame(
            "{\"kind\":\"header\"}\n{\"kind\":\"entry\"}\n{\"kind\":\"footer\"}\n",
            gzdecode($gzipBytes),
        );
    }

    public function testItIsFullAtTheEntryBudget(): void
    {
        $buffer = new BackupPartBuffer();
        for ($index = 1; $index < BackupPartBuffer::MAX_ENTRIES; ++$index) {
            $buffer->add('{}', null);
        }
        self::assertFalse($buffer->isFull());
        $buffer->add('{}', null);
        self::assertTrue($buffer->isFull());
    }

    public function testItIsFullAtTheByteBudget(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add(str_repeat('a', BackupPartBuffer::MAX_BYTES - 1), null);
        self::assertFalse($buffer->isFull());
        $buffer->add('a', null);
        self::assertTrue($buffer->isFull());
    }
}
