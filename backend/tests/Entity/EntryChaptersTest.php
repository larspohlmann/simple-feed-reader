<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryChapter;
use App\Entity\EntryChapters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryChaptersTest extends TestCase
{
    public function testDeclaredChaptersReadBackInOrder(): void
    {
        $chapters = new EntryChapters();

        $chapters->declare([new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')], null);

        self::assertEquals([new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')], $chapters->getChapters());
        self::assertNull($chapters->getUrl());
    }

    public function testAUrlIsKeptOnlyWhenNoChaptersCameInline(): void
    {
        $inline = new EntryChapters();
        $inline->declare([new EntryChapter(0, 'Intro'), new EntryChapter(90, 'Main')], 'https://cdn.test/ch.json');
        $linked = new EntryChapters();
        $linked->declare([], 'https://cdn.test/ch.json');

        self::assertNull($inline->getUrl());
        self::assertSame('https://cdn.test/ch.json', $linked->getUrl());
    }

    public function testAnOverlongUrlIsDropped(): void
    {
        $chapters = new EntryChapters();

        $chapters->declare([], 'https://cdn.test/' . str_repeat('a', EntryChapters::URL_MAX_LENGTH));

        self::assertNull($chapters->getUrl());
    }

    public function testFillingStoresTheChaptersAndForgetsTheUrl(): void
    {
        $chapters = new EntryChapters();
        $chapters->declare([], 'https://cdn.test/ch.json');

        $chapters->fill([new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')]);

        self::assertCount(2, $chapters->getChapters());
        self::assertNull($chapters->getUrl());
    }

    public function testFillingWithNothingStillForgetsTheUrl(): void
    {
        $chapters = new EntryChapters();
        $chapters->declare([], 'https://cdn.test/ch.json');

        $chapters->fill([]);

        self::assertSame([], $chapters->getChapters());
        self::assertNull($chapters->getUrl());
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('storedRows')]
    public function testOnlyARowWithAStartAndATitleIsComplete(array $stored, bool $expected): void
    {
        self::assertSame($expected, EntryChapter::isComplete($stored));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function storedRows(): iterable
    {
        yield 'no title' => [['startSeconds' => 5], false];
        yield 'no start' => [['title' => 'x'], false];
        yield 'both' => [['startSeconds' => 5, 'title' => 'x'], true];
    }
}
