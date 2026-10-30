<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\Support;

use App\Entity\EntryChapter;
use App\Service\Chapter\Support\DescriptionChapters;
use App\Service\Text\Support\LinkedPlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionChaptersTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/Chapter/';
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

    /** @return iterable<string, array{string, list<EntryChapter>}> */
    public static function lineShapes(): iterable
    {
        yield 'a stamp after a number prefix' => [
            '<p>01. 00:00 A<br>02. 01:00 B<br>03. 02:00 C</p>',
            [new EntryChapter(0, '01. A'), new EntryChapter(60, '02. B'), new EntryChapter(120, '03. C')],
        ];
        yield 'a bracketed stamp at the end' => [
            '<p>1. A (00:00)<br>2. B (01:00)<br>3. C (02:00)</p>',
            [new EntryChapter(0, '1. A'), new EntryChapter(60, '2. B'), new EntryChapter(120, '3. C')],
        ];
        yield 'a leading range' => [
            '<p>0:00 - 1:00 A<br>1:00 - 2:00 B<br>2:00 - 3:00 C</p>',
            [new EntryChapter(0, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
        ];
        yield 'a bare stamp at the end' => [
            '<p>1. A (Label) 00:00<br>2. B (Label) 01:00<br>3. C (Label) 02:00</p>',
            [
                new EntryChapter(0, '1. A (Label)'),
                new EntryChapter(60, '2. B (Label)'),
                new EntryChapter(120, '3. C (Label)'),
            ],
        ];
    }

    /** @param list<EntryChapter> $chapters */
    #[DataProvider('lineShapes')]
    public function testReadsAStampAnywhereInTheLine(string $html, array $chapters): void
    {
        self::assertEquals($chapters, DescriptionChapters::in($html)->chapters);
    }

    /** @return iterable<string, array{string, int, string, int, string}> */
    public static function youTubeDescriptions(): iterable
    {
        // The counts and starts are YouTube's own, read from each watch page's chapter list on 2026-10-26.
        yield '570hdDp8l4g' => [
            '570hdDp8l4g',
            19,
            '01. IKØN - A World Between Us [Iboga Records]',
            4543,
            '19. Redrosid - Shrouded [Iono Music]',
        ];
        yield 'P1Kncl21hEU' => ['P1Kncl21hEU', 8, '1. Disclosure Day', 2489, '8. We Have Bled And Died For You'];
        yield 'RYHYRmr3344' => [
            'RYHYRmr3344',
            15,
            '1. Critical Choice - For Those Who Dance (Iboga Records)',
            5138,
            '15. Outsiders Vs. Melodeep - Arvore Da Vida (Shamanic Tales)',
        ];
        yield 'SxcUgxsPahg' => ['SxcUgxsPahg', 15, 'Migdalor Remix ID (Unreleased)', 4988, 'Migdalor - Different Kind'];
    }

    #[DataProvider('youTubeDescriptions')]
    public function testReadsAsManyChaptersAsYouTubeDoes(
        string $video,
        int $count,
        string $firstTitle,
        int $lastStart,
        string $lastTitle,
    ): void {
        $description = (string) file_get_contents(self::FIXTURES . 'youtube-description-' . $video . '.txt');

        $chapters = DescriptionChapters::in((string) LinkedPlainText::asHtml($description))->chapters;

        self::assertCount($count, $chapters);
        self::assertEquals(new EntryChapter(0, $firstTitle), $chapters[0]);
        self::assertEquals(new EntryChapter($lastStart, $lastTitle), $chapters[$count - 1]);
    }

    public function testAPlainLineBetweenChapterLinesStaysInTheBody(): void
    {
        $described = DescriptionChapters::in('<p>0:00 A<br>1:00 B<br>Sponsor<br>2:00 C</p>');

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>Sponsor</p>', $described->contentHtml);
    }

    public function testTheTracklistKeepsTheLinksBetweenItsLinesInTheBody(): void
    {
        $description = (string) file_get_contents(self::FIXTURES . 'youtube-description-570hdDp8l4g.txt');

        $described = DescriptionChapters::in((string) LinkedPlainText::asHtml($description));

        self::assertCount(19, $described->chapters);
        self::assertNotNull($described->contentHtml);
        self::assertStringContainsString('BUY <a href="https://', $described->contentHtml);
    }

    public function testAStamplessParagraphAfterTheRunEndsIt(): void
    {
        $html = '<p>0:00 A<br>1:00 B<br>2:00 C</p>'
            . '<p>Thanks for watching!</p><p>Live stream every Friday at 20:00 CET</p>';

        $described = DescriptionChapters::in($html);

        self::assertEquals(
            [new EntryChapter(0, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
        self::assertSame(
            '<p>Thanks for watching!</p><p>Live stream every Friday at 20:00 CET</p>',
            $described->contentHtml,
        );
    }

    public function testRemovesABlockWithStampsAtTheEndFromTheBody(): void
    {
        $described = DescriptionChapters::in(
            '<p>Intro</p><p>1. A (00:00)<br>2. B (01:00)<br>3. C 02:00</p><p>Outro</p>',
        );

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>Intro</p><p>Outro</p>', $described->contentHtml);
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
        yield 'broken by a line with two stamps' => ['<p>0:00 A<br>1:00 B<br>1:30 to 2:00 C<br>3:00 D</p>'];
        yield 'broken by a bare stamp' => ['<p>0:00 A<br>1:00 B<br>1:30<br>3:00 D</p>'];
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

    public function testReadsLinesThatSitDirectlyInTheBody(): void
    {
        $described = DescriptionChapters::in('Intro<br>0:00 A<br>1:00 B<br>2:00 C<br>Outro');

        self::assertCount(3, $described->chapters);
        self::assertSame('Intro<br>Outro', $described->contentHtml);
    }

    public function testKeepsTheBodyThatHeldOnlyTheBlock(): void
    {
        $described = DescriptionChapters::in('0:00 A<br>1:00 B<br>2:00 C');

        self::assertCount(3, $described->chapters);
        self::assertSame('', $described->contentHtml);
    }

    public function testReadsTheLinesAroundAListInReadingOrder(): void
    {
        $described = DescriptionChapters::in('0:00 A<ul><li>1:00 B</li></ul>2:00 C');

        self::assertEquals(
            [new EntryChapter(0, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
    }

    public function testReadsLinesInsideADivision(): void
    {
        $described = DescriptionChapters::in('<div>0:00 A<br>1:00 B<br>2:00 C</div><div>Outro</div>');

        self::assertCount(3, $described->chapters);
    }

    /** @return iterable<string, array{string}> */
    public static function wrappedParagraphs(): iterable
    {
        yield 'span' => ['<span><p>0:00 A<br>1:00 B<br>2:00 C</p></span>'];
        yield 'font' => ['<font><p>0:00 A<br>1:00 B<br>2:00 C</p></font>'];
        yield 'main' => ['<main><p>0:00 A<br>1:00 B<br>2:00 C</p></main>'];
        yield 'details' => ['<details><p>0:00 A<br>1:00 B<br>2:00 C</p></details>'];
    }

    #[DataProvider('wrappedParagraphs')]
    public function testReadsAParagraphWrappedInAnUnlistedElement(string $html): void
    {
        self::assertCount(3, DescriptionChapters::in($html)->chapters);
    }

    public function testWhitespaceBetweenListItemsKeepsTheRun(): void
    {
        $described = DescriptionChapters::in("<ul>\n<li>0:00 A</li>\n<li>1:00 B</li>\n<li>2:00 C</li>\n</ul>");

        self::assertCount(3, $described->chapters);
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

    /** @return iterable<string, array{string, list<string>}> */
    public static function proseAfterTheRun(): iterable
    {
        yield 'an inner stamp in the same paragraph' => [
            "0:00 Intro\n1:00 B\n2:00 C\nThanks for watching\nLive stream every Friday at 20:00 CET",
            ['Thanks for watching', 'Live stream every Friday at 20:00 CET'],
        ];
        yield 'an inner stamp in the next paragraph' => [
            "0:00 Intro\n1:00 B\n2:00 C\n\nLive stream every Friday at 20:00 CET",
            ['Live stream every Friday at 20:00 CET'],
        ];
        yield 'a trailing stamp after a leading run' => [
            "Timestamps:\n0:00 Intro\n1:00 B\n2:00 C\nSubscribe!\nRecorded 2024-05-01 at 21:30",
            ['Timestamps:', 'Subscribe!', 'Recorded 2024-05-01 at 21:30'],
        ];
        yield 'an inner stamp beside a link' => [
            "0:00 Intro\n1:00 B\n2:00 C\nSee also https://youtu.be/abc?t=90 and 3:15 for the bonus",
            ['See also <a href="https://youtu.be/abc?t=90">https://youtu.be/abc?t=90</a> and 3:15 for the bonus'],
        ];
    }

    /** @param list<string> $keptLines */
    #[DataProvider('proseAfterTheRun')]
    public function testAStampOfAnotherPositionEndsTheRunAndStaysInTheBody(string $description, array $keptLines): void
    {
        $described = DescriptionChapters::in((string) LinkedPlainText::asHtml($description));

        self::assertEquals(
            [new EntryChapter(0, 'Intro'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
        foreach ($keptLines as $keptLine) {
            self::assertStringContainsString($keptLine, (string) $described->contentHtml);
        }
    }

    public function testATrailingStampContinuesATrailingRun(): void
    {
        $description = "A 0:00\nB 1:00\nC 2:00\nRecorded at 21:30";

        $described = DescriptionChapters::in((string) LinkedPlainText::asHtml($description));

        self::assertEquals(
            [
                new EntryChapter(0, 'A'),
                new EntryChapter(60, 'B'),
                new EntryChapter(120, 'C'),
                new EntryChapter(1290, 'Recorded at'),
            ],
            $described->chapters,
        );
    }

    public function testALeadingStampEndsATrailingRun(): void
    {
        $described = DescriptionChapters::in('<p>A 0:00<br>B 1:00<br>C 2:00<br>3:00 D</p>');

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>3:00 D</p>', $described->contentHtml);
    }

    public function testARunThatSwitchesPositionAfterItsFirstLineIsNoChapterBlock(): void
    {
        $html = '<p>0:00 A<br>B 1:00<br>C 2:00<br>D 3:00</p>';

        $described = DescriptionChapters::in($html);

        self::assertSame([], $described->chapters);
        self::assertSame($html, $described->contentHtml);
    }

    public function testAnEpisodeRunMayStartAfterZero(): void
    {
        $described = DescriptionChapters::inEpisode('<p>0:10 A<br>1:00 B<br>2:00 C</p>');

        self::assertEquals(
            [new EntryChapter(10, 'A'), new EntryChapter(60, 'B'), new EntryChapter(120, 'C')],
            $described->chapters,
        );
        self::assertSame('', $described->contentHtml);
    }

    public function testAnEpisodeRunStillNeedsThreeLines(): void
    {
        self::assertSame([], DescriptionChapters::inEpisode('<p>0:10 A<br>1:00 B</p>')->chapters);
    }

    public function testAVideoSkipsARunThatStartsAfterZeroAndAnEpisodeTakesIt(): void
    {
        $html = '<p>0:10 A<br>1:00 B<br>2:00 C</p><p>0:00 X<br>1:00 Y<br>2:00 Z</p>';

        self::assertSame('X', DescriptionChapters::in($html)->chapters[0]->title);
        self::assertSame('A', DescriptionChapters::inEpisode($html)->chapters[0]->title);
    }

    public function testAnEpisodeRunEndsAtItsFirstUnstampedLine(): void
    {
        $described = DescriptionChapters::inEpisode(
            '(02:23) A<br>(06:38) B<br>(25:20) C<br><br>Mehr zum Thema<br>Lorem<br>30:00 Uhr Wiederholung',
        );

        self::assertEquals(
            [new EntryChapter(143, 'A'), new EntryChapter(398, 'B'), new EntryChapter(1520, 'C')],
            $described->chapters,
        );
        self::assertNotNull($described->contentHtml);
        self::assertStringContainsString('30:00 Uhr Wiederholung', $described->contentHtml);
    }

    public function testAnEpisodeTakesNoStampsScatteredBetweenUnstampedLines(): void
    {
        $html = 'Sprecher: X<br>Zusp. Floßmann (4:02)<br>Text<br>Zusp. Meier (7:10)<br>More<br>Zusp. Kunz (9:00)';

        $described = DescriptionChapters::inEpisode($html);

        self::assertSame([], $described->chapters);
        self::assertSame($html, $described->contentHtml);
    }

    public function testAnEpisodeKeepsTheChapterLinesPastItsEndInTheBody(): void
    {
        $described = DescriptionChapters::inEpisodeLasting('<p>0:10 A<br>1:00 B<br>2:00 C<br>3:00 D</p>', 180);

        self::assertCount(3, $described->chapters);
        self::assertSame('<p>3:00 D</p>', $described->contentHtml);
    }

    public function testAnEpisodeWithFewerThanThreeChaptersBeforeItsEndKeepsItsBody(): void
    {
        $html = '<p>0:10 A<br>1:00 B<br>2:00 C</p>';

        $described = DescriptionChapters::inEpisodeLasting($html, 120);

        self::assertSame([], $described->chapters);
        self::assertSame($html, $described->contentHtml);
    }

    public function testReadsADeutschlandfunkEpisodeDescription(): void
    {
        $html = (string) file_get_contents(self::FIXTURES . 'deutschlandfunk-description-ki-verstehen.html');

        $described = DescriptionChapters::inEpisode($html);

        self::assertEquals(
            [
                new EntryChapter(143, 'Wie weit deutsche Unternehmen beim KI-Einsatz sind'),
                new EntryChapter(398, 'Erst in der Integrationsphase steigt die Produktivität'),
                new EntryChapter(677, 'KI ist viel mehr als Chatbots und kann sogar Stahlrohre prüfen'),
                new EntryChapter(912, 'Die Kehrseite des KI-Booms und die Angst vor dem Jobverlust'),
                new EntryChapter(1140, 'Nvidia-Boss Jensen Huang sagt: KI schafft massenhaft neue Arbeitsplätze'),
                new EntryChapter(1520, 'Das Produktivitätsparadoxon und die J-Kurve'),
            ],
            $described->chapters,
        );
        self::assertNotNull($described->contentHtml);
        self::assertStringNotContainsString('(02:23)', $described->contentHtml);
        self::assertStringNotContainsString('(25:20)', $described->contentHtml);
        self::assertStringContainsString(
            '<strong>Das erwartet Euch in dieser Folge:</strong>',
            $described->contentHtml,
        );
        self::assertStringContainsString('Barbara Engels', $described->contentHtml);
        self::assertStringContainsString(
            '<strong>Mehr zum Thema in der Deutschlandfunk-App:</strong>',
            $described->contentHtml,
        );
    }
}
