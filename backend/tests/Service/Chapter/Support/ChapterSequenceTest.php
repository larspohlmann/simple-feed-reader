<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Support\ChapterSequence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChapterSequenceTest extends TestCase
{
    public function testSortsByStart(): void
    {
        $ordered = ChapterSequence::ordered([new EntryChapter(90, 'B'), new EntryChapter(0, 'A')]);

        self::assertSame(['A', 'B'], array_map(static fn (EntryChapter $chapter): string => $chapter->title, $ordered));
    }

    public function testASingleChapterIsNoList(): void
    {
        self::assertSame([], ChapterSequence::ordered([new EntryChapter(0, 'Only')]));
    }

    public function testTwoChaptersAtTheSameStartAreNoList(): void
    {
        self::assertSame([], ChapterSequence::ordered([new EntryChapter(0, 'A'), new EntryChapter(0, 'B')]));
    }

    /** @return iterable<string, array{list<EntryChapter>, bool}> */
    public static function sequences(): iterable
    {
        yield 'empty' => [[], true];
        yield 'single' => [[new EntryChapter(0, 'A')], true];
        yield 'strictly ascending' => [[new EntryChapter(0, 'A'), new EntryChapter(10, 'B')], true];
        yield 'equal neighbours' => [
            [new EntryChapter(0, 'A'), new EntryChapter(10, 'B'), new EntryChapter(10, 'C')],
            false,
        ];
        yield 'descending pair' => [[new EntryChapter(10, 'B'), new EntryChapter(0, 'A')], false];
    }

    /** @param list<EntryChapter> $chapters */
    #[DataProvider('sequences')]
    public function testAscendsOnlyForStrictlyIncreasingStarts(array $chapters, bool $expected): void
    {
        self::assertSame($expected, ChapterSequence::ascends($chapters));
    }
}
