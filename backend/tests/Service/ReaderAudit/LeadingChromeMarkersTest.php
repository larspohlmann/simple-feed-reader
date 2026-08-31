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
