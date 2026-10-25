<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Support\DescriptionChapters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionChaptersTest extends TestCase
{
    private const string VERITASIUM = '<p>Head to https://brilliant.org</p>'
        . '<p>▀▀▀<br />0:00 Who invented Enigma?<br />4:16 How did Enigma work?<br />10:51 The Secret Spy Mansion</p>'
        . '<p>▀▀▀<br />A huge thanks</p>';

    public function testReadsTheChapterBlock(): void
    {
        $described = DescriptionChapters::in(self::VERITASIUM);

        self::assertEquals(
            [
                new EntryChapter(0, 'Who invented Enigma?'),
                new EntryChapter(256, 'How did Enigma work?'),
                new EntryChapter(651, 'The Secret Spy Mansion'),
            ],
            $described->chapters,
        );
    }

    public function testRemovesTheBlockAndItsBreaksFromTheBody(): void
    {
        $described = DescriptionChapters::in(self::VERITASIUM);

        self::assertSame('<p>Head to https://brilliant.org</p><p>▀▀▀</p><p>▀▀▀<br>A huge thanks</p>', $described->body);
    }

    public function testKeepsTheTextAroundABlockInsideOneParagraph(): void
    {
        $described = DescriptionChapters::in('<p>Intro<br>0:00 A<br>1:00 B<br>2:00 C<br>Outro</p>');

        self::assertSame('<p>Intro<br>Outro</p>', $described->body);
    }

    public function testDropsAParagraphThatHeldOnlyTheBlock(): void
    {
        $described = DescriptionChapters::in('<p>Intro</p><p>0:00 A<br>1:00 B<br>2:00 C</p><p>Outro</p>');

        self::assertSame('<p>Intro</p><p>Outro</p>', $described->body);
    }

    public function testReadsBracketsSeparatorsAndHours(): void
    {
        $described = DescriptionChapters::in('<p>(0:00) Start<br>[12:30] - Middle<br>1:02:03 | End</p>');

        self::assertEquals(
            [new EntryChapter(0, 'Start'), new EntryChapter(750, 'Middle'), new EntryChapter(3723, 'End')],
            $described->chapters,
        );
    }

    public function testAChapterTitleKeepsItsLink(): void
    {
        $described = DescriptionChapters::in('<p>0:00 A<br>1:00 <a href="https://x.test">B</a><br>2:00 C</p>');

        self::assertSame('B', $described->chapters[1]->title);
    }

    /** @return iterable<string, array{string}> */
    public static function notChapters(): iterable
    {
        yield 'no 0:00' => ['<p>0:10 A<br>1:00 B<br>2:00 C</p>'];
        yield 'only two' => ['<p>0:00 A<br>1:00 B</p>'];
        yield 'out of order' => ['<p>0:00 A<br>2:00 B<br>1:00 C</p>'];
        yield 'a stamp inside prose' => ['<p>At 0:00 it starts, at 1:00 it builds and at 2:00 it ends.</p>'];
        yield 'broken by a plain line' => ['<p>0:00 A<br>1:00 B<br>Sponsor<br>2:00 C</p>'];
        yield 'empty' => [''];
    }

    #[DataProvider('notChapters')]
    public function testLeavesABodyWithoutTheBlockAlone(string $html): void
    {
        $described = DescriptionChapters::in($html);

        self::assertSame([], $described->chapters);
        self::assertSame($html, $described->body);
    }

    public function testOnlyTheFirstBlockCountsAndARepeatStays(): void
    {
        $html = '<p>0:00 A<br>1:00 B<br>2:00 C</p><p>Deutsch:</p><p>0:00 A<br>1:00 B<br>2:00 C</p>';

        $described = DescriptionChapters::in($html);

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>Deutsch:</p><p>0:00 A<br>1:00 B<br>2:00 C</p>', $described->body);
    }
}
