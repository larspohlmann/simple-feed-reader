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

        self::assertSame(
            '<p>Head to https://brilliant.org</p><p>▀▀▀</p><p>▀▀▀<br>A huge thanks</p>',
            $described->contentHtml,
        );
    }

    public function testKeepsTheTextAroundABlockInsideOneParagraph(): void
    {
        $described = DescriptionChapters::in('<p>Intro<br>0:00 A<br>1:00 B<br>2:00 C<br>Outro</p>');

        self::assertSame('<p>Intro<br>Outro</p>', $described->contentHtml);
    }

    public function testDropsAParagraphThatHeldOnlyTheBlock(): void
    {
        $described = DescriptionChapters::in('<p>Intro</p><p>0:00 A<br>1:00 B<br>2:00 C</p><p>Outro</p>');

        self::assertSame('<p>Intro</p><p>Outro</p>', $described->contentHtml);
    }

    public function testReadsBracketsSeparatorsAndHours(): void
    {
        $described = DescriptionChapters::in('<p>(0:00) Start<br>[12:30] - Middle<br>1:02:03 | End</p>');

        self::assertEquals(
            [new EntryChapter(0, 'Start'), new EntryChapter(750, 'Middle'), new EntryChapter(3723, 'End')],
            $described->chapters,
        );
    }

    public function testAChapterTitleIsThePlainTextOfItsLink(): void
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
        yield 'seconds with a third digit' => ['<p>0:123 A<br>1:123 B<br>2:123 C</p>'];
        yield 'empty' => [''];
    }

    #[DataProvider('notChapters')]
    public function testLeavesABodyWithoutTheBlockAlone(string $html): void
    {
        $described = DescriptionChapters::in($html);

        self::assertSame([], $described->chapters);
        self::assertSame($html, $described->contentHtml);
    }

    public function testOnlyTheFirstBlockCountsAndARepeatStays(): void
    {
        $html = '<p>0:00 A<br>1:00 B<br>2:00 C</p><p>Deutsch:</p><p>0:00 A<br>1:00 B<br>2:00 C</p>';

        $described = DescriptionChapters::in($html);

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>Deutsch:</p><p>0:00 A<br>1:00 B<br>2:00 C</p>', $described->contentHtml);
    }

    public function testKeepsABlockThatStillHoldsAnElement(): void
    {
        $described = DescriptionChapters::in('<p><img src="x"><br>0:00 A<br>1:00 B<br>2:00 C</p>');

        self::assertCount(3, $described->chapters);
        self::assertSame('<p><img src="x"></p>', $described->contentHtml);
    }

    public function testDropsATrailingBreakFollowedByWhitespace(): void
    {
        $described = DescriptionChapters::in("<p>Intro<br>0:00 A<br>1:00 B<br>2:00 C<br> </p>");

        self::assertSame('<p>Intro </p>', $described->contentHtml);
    }

    public function testReadsChaptersFromParagraphsNestedInListItems(): void
    {
        $described = DescriptionChapters::in(
            '<ul><li><p>0:00 A</p></li><li><p>1:00 B</p></li><li><p>2:00 C</p></li></ul>',
        );

        self::assertCount(3, $described->chapters);
        self::assertSame('', $described->contentHtml);
    }

    public function testDropsAnOrderedListEmptiedOfItsItemsButKeepsTheTextAround(): void
    {
        $described = DescriptionChapters::in(
            '<p>Before</p><ol><li>0:00 A</li><li>1:00 B</li><li>2:00 C</li></ol><p>After</p>',
        );

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>Before</p><p>After</p>', $described->contentHtml);
    }

    public function testKeepsAListThatStillHoldsAnotherItem(): void
    {
        $described = DescriptionChapters::in('<ul><li>0:00 A</li><li>1:00 B</li><li>2:00 C</li><li>Links</li></ul>');

        self::assertSame('<ul><li>Links</li></ul>', $described->contentHtml);
    }

    public function testReadsChaptersFromANestedListWithoutTheOuterItemText(): void
    {
        $described = DescriptionChapters::in(
            '<ul><li>Intro<ul><li>0:00 A</li><li>1:00 B</li><li>2:00 C</li></ul></li></ul>',
        );

        self::assertCount(3, $described->chapters);
        self::assertSame('<ul><li>Intro</li></ul>', $described->contentHtml);
    }

    public function testReturnsTheBodyVerbatimWhenNothingIsRead(): void
    {
        $html = '<p>No chapters<br />here</p>';

        self::assertSame($html, DescriptionChapters::in($html)->contentHtml);
    }

    public function testDropsABlockLeftHoldingOnlyWhitespace(): void
    {
        $described = DescriptionChapters::in('<p>0:00 A<br>1:00 B<br>2:00 C<br> </p>');

        self::assertSame('', $described->contentHtml);
    }
}
