<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\LeadingChromeMarkers;
use PHPUnit\Framework\TestCase;

final class LeadingChromeMarkersTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und damit die Stelle markiert, an der der '
        . 'Artikel beginnt und die Kopfzone endet. ';

    private LeadingChromeMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new LeadingChromeMarkers();
    }

    public function testReportsARessortListStandingAboveTheFirstParagraph(): void
    {
        $menu = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 4) . '</ul>';

        self::assertSame(['leading_link_list'], $this->codesFor($menu . $this->article()));
    }

    public function testTheSameListUnderTheArticleIsTheSitesTailAndIsToleratedNotReported(): void
    {
        // The Deutschlandfunk pages this rule was rebuilt for: a related-articles
        // list under the last paragraph, on a body the reader renders correctly.
        $related = '<ul>' . str_repeat('<li><a href="/x">Mehr dazu</a></li>', 6) . '</ul>';

        self::assertSame([], $this->codesFor($this->article() . $related));
    }

    public function testReportsAMenuBuiltFromBlocksRatherThanAList(): void
    {
        $menu = str_repeat('<p><a href="/x">Politik</a></p>', 4);

        self::assertSame(['leading_nav_run'], $this->codesFor($menu . $this->article()));
    }

    public function testReportsAWallOfLinksTheReaderMustScrollPastToReachTheArticle(): void
    {
        // Neither a list nor a run: link-carrying lines mixed with short labels,
        // which is how a masthead with a search widget arrives.
        $header = str_repeat('<p>Ressort <a href="/x">Politik</a></p><p>Kurz</p>', 5);

        self::assertContains('leading_link_wall', $this->codesFor($header . $this->article()));
    }

    public function testAnArticleThatStartsAtOnceEarnsNothing(): void
    {
        self::assertSame([], $this->codesFor($this->article()));
    }

    public function testALinkInsideTheFirstParagraphIsNotChrome(): void
    {
        $withLinks = '<p>' . self::PROSE . '<a href="/a">Quelle</a> und <a href="/b">Beleg</a>.</p>';

        self::assertSame([], $this->codesFor($withLinks));
    }

    public function testThreeLinkOnlyItemsAreAListAndTwoAreNot(): void
    {
        // The threshold is a judgement, so it is stated here rather than only in
        // the constant: two ressort links above an article happen, a row of
        // three is a menu.
        $two = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 2) . '</ul>';
        $three = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 3) . '</ul>';

        self::assertSame([], $this->codesFor($two . $this->article()));
        self::assertSame(['leading_link_list'], $this->codesFor($three . $this->article()));
    }

    public function testFourConsecutiveLinkOnlyBlocksAreARunAndThreeAreNot(): void
    {
        $three = str_repeat('<p><a href="/x">Politik</a></p>', 3);
        $four = str_repeat('<p><a href="/x">Politik</a></p>', 4);

        self::assertSame([], $this->codesFor($three . $this->article()));
        self::assertSame(['leading_nav_run'], $this->codesFor($four . $this->article()));
    }

    public function testTheRunMustBeConsecutiveAndProseBetweenTwoHalvesBreaksIt(): void
    {
        $split = str_repeat('<p><a href="/x">Politik</a></p>', 2)
            . '<p>Ein kurzer Zwischentext.</p>'
            . str_repeat('<p><a href="/x">Kultur</a></p>', 2);

        self::assertSame([], $this->codesFor($split . $this->article()));
    }

    public function testTheLinkWallNeedsBothItsLinksAndItsBlocks(): void
    {
        // Five links in five blocks is a short teaser row; the wall is about how
        // far the reader has to scroll, so it takes six blocks as well.
        $shortRow = str_repeat('<p>Ressort <a href="/x">Politik</a></p>', 5);
        $wall = $shortRow . '<p>Kurz</p>';
        $tooFewLinks = str_repeat('<p>Ressort <a href="/x">Politik</a></p>', 4) . str_repeat('<p>Kurz</p>', 3);

        self::assertNotContains('leading_link_wall', $this->codesFor($shortRow . $this->article()));
        self::assertNotContains('leading_link_wall', $this->codesFor($tooFewLinks . $this->article()));
        self::assertContains('leading_link_wall', $this->codesFor($wall . $this->article()));
    }

    public function testEachMarkerCarriesItsWeightAndTheStageToLookAt(): void
    {
        $menu = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 4) . '</ul>';
        $markers = $this->markers->detect(ExtractedBody::fromHtml($menu . $this->article()));

        self::assertSame(4, $markers[0]->weight);
        self::assertSame('NavigationChromeTrimmer', $markers[0]->suspect);
        self::assertSame(
            '4 link-only list items stand before the first paragraph: '
            . '"Ressort" | "Ressort" | "Ressort" | …',
            $markers[0]->detail,
        );
    }

    public function testTheLinkWallDetailNamesBothCounts(): void
    {
        $wall = str_repeat('<p>Ressort <a href="/x">Politik</a></p>', 5) . '<p>Kurz</p>';
        $markers = $this->markers->detect(ExtractedBody::fromHtml($wall . $this->article()));

        self::assertSame(3, $markers[0]->weight);
        self::assertSame(
            '5 links across 6 blocks before the article starts: '
            . '"Ressort Politik" | "Ressort Politik" | "Ressort Politik" | …',
            $markers[0]->detail,
        );
    }

    public function testTheNavRunDetailReportsTheLongestRunNotTheLast(): void
    {
        $html = str_repeat('<p><a href="/x">A</a></p>', 5) . '<p>Kurz</p>'
            . str_repeat('<p><a href="/y">B</a></p>', 4);
        $markers = $this->markers->detect(ExtractedBody::fromHtml($html . $this->article()));
        $run = array_values(array_filter(
            $markers,
            static fn (CleanupMarker $marker): bool => $marker->code === 'leading_nav_run',
        ));

        self::assertSame(
            '5 consecutive link-only blocks before the first paragraph: "A" | "A" | "A" | …',
            $run[0]->detail,
        );
    }

    private function article(): string
    {
        return '<p>' . str_repeat(self::PROSE, 2) . '</p><p>' . self::PROSE . '</p>';
    }

    /** @return list<string> */
    private function codesFor(string $html): array
    {
        return array_map(
            static fn (CleanupMarker $marker): string => $marker->code,
            $this->markers->detect(ExtractedBody::fromHtml($html)),
        );
    }
}
