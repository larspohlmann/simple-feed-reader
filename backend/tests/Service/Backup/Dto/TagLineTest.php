<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\Tag;
use App\Service\Backup\Dto\TagLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class TagLineTest extends TestCase
{
    public function testALineWithoutAParentKeyReadsAsARoot(): void
    {
        $line = TagLine::fromLine(
            ['kind' => 'tag', 'name' => 'Reading', 'color' => null, 'icon' => null, 'position' => 0],
        );

        self::assertNull($line->parent);
    }

    public function testALineNamingAParentCarriesIt(): void
    {
        $line = TagLine::fromLine(
            [
                'kind' => 'tag', 'name' => 'Long reads', 'color' => null, 'icon' => null, 'position' => 1,
                'parent' => 'Reading',
            ],
        );

        self::assertSame('Reading', $line->parent);
    }

    public function testItWritesAChildTagNamingItsParent(): void
    {
        $user = BackupLineRecords::user();
        $tag = new Tag($user, 'Long reads');
        $tag->setColor('#123456');
        $tag->setIcon('flask');
        $tag->setPosition(2);
        $tag->setParent(new Tag($user, 'Reading'));

        self::assertSame(
            '{"kind":"tag","name":"Long reads","color":"#123456","icon":"flask","position":2,"parent":"Reading"}',
            TagLine::of($tag)->toBackupLine(),
        );
    }

    public function testItWritesARootTagWithANullParent(): void
    {
        self::assertSame(
            '{"kind":"tag","name":"Reading","color":null,"icon":null,"position":0,"parent":null}',
            TagLine::of(new Tag(BackupLineRecords::user(), 'Reading'))->toBackupLine(),
        );
    }
}
