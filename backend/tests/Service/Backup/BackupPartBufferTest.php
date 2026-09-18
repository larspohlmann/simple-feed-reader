<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupPartBuffer;
use PHPUnit\Framework\TestCase;

final class BackupPartBufferTest extends TestCase
{
    public function testDrainWritesHeaderEntriesThenStatesThenAFooterWithThisPartsCounts(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add('{"kind":"entry","n":1}', '{"kind":"entryState","n":1}');
        $buffer->add('{"kind":"entry","n":2}', null);

        $lines = explode("\n", rtrim((string) gzdecode($buffer->drain('{"kind":"header"}')), "\n"));

        self::assertSame([
            '{"kind":"header"}',
            '{"kind":"entry","n":1}',
            '{"kind":"entry","n":2}',
            '{"kind":"entryState","n":1}',
            '{"kind":"footer","counts":{"tag":0,"savedSearch":0,"feed":0,"subscription":0,"entry":2,"entryState":1}}',
        ], $lines);
        self::assertTrue($buffer->isEmpty());
    }

    public function testItIsFullAtTheEntryBudget(): void
    {
        $buffer = new BackupPartBuffer();
        for ($i = 1; $i < BackupPartBuffer::MAX_ENTRIES; ++$i) {
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
