<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\NavigationChromeTrimmer;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use App\Tests\Support\ProseParagraphs;
use PHPUnit\Framework\TestCase;

final class NavigationChromeTrimmerTest extends TestCase
{
    use ParsesHtml;

    private NavigationChromeTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new NavigationChromeTrimmer();
    }

    public function testRemovesASiteHeaderRegionThatReadabilityKeptBeforeTheArticle(): void
    {
        // A masthead built from content <div>s is scored as article content. The nav landmark anchors the region,
        // and the whole link-dominated header block around it is chrome.
        $header = '<div class="site-header">'
            . '<a href="https://example.test/"><img src="https://example.test/logo.svg" alt="Logo"></a>'
            . '<div role="dialog"><p>Results for {phrase}</p></div>'
            . '<nav><ul><li><a href="/a">Editorial</a></li><li><a href="/b">Blog</a></li>'
            . '<li><a href="/c">Debate</a></li><li><a href="/d">Books</a></li>'
            . '<li><a href="/e">About</a></li><li><a href="/f">Newsletter</a></li></ul></nav>'
            . '</div>';
        $html = '<div id="wrap">' . $header . '<main><p>' . ProseParagraphs::SUBSTANTIAL . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('site-header', $result);
        self::assertStringNotContainsString('{phrase}', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesALeadingHeaderElement(): void
    {
        $header = '<header><a href="/a">Editorial</a><a href="/b">Blog</a>'
            . '<a href="/c">Debate</a></header>';
        $html = '<div>' . $header . '<main><p>' . ProseParagraphs::SUBSTANTIAL . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('Editorial', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesALeadingRoleNavigationRegionCaseInsensitively(): void
    {
        // The role match lower-cases first, so a capitalised role still counts.
        $nav = '<div role="Navigation"><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a></div>';
        $html = '<div>' . $nav . '<main><p>' . ProseParagraphs::SUBSTANTIAL . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('Editorial', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesEveryNavigationRegionNotJustTheFirst(): void
    {
        $first = '<div class="nav-one"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a></nav></div>';
        $second = '<div class="nav-two"><nav><a href="/d">Jobs</a>'
            . '<a href="/e">About</a><a href="/f">Books</a></nav></div>';
        $html = '<div>' . $first . '<main><p>' . ProseParagraphs::SUBSTANTIAL . '</p></main>' . $second . '</div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('nav-one', $result);
        self::assertStringNotContainsString('nav-two', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testKeepsAnInArticleNavYetStillRemovesLaterChrome(): void
    {
        // The in-article nav is skipped, not a stopping point: a chrome region
        // that comes after it in document order is still removed.
        $toc = '<main><nav class="toc"><a href="https://example.test/x">Kapitel</a></nav>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></main>';
        $tail = '<div class="tail-nav"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a></nav></div>';
        $html = '<div>' . $toc . $tail . '</div>';

        $result = $this->trimmed($html);

        self::assertStringContainsString('toc', $result);
        self::assertStringNotContainsString('tail-nav', $result);
    }

    public function testKeepsAnInArticleTableOfContentsNavInsideArticle(): void
    {
        // The boundary guard covers <article> as well as <main>: a nav inside a
        // semantic <article> is in-content and stays.
        $toc = '<article><nav><a href="https://example.test/a">Abschnitt A</a></nav>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></article>';

        self::assertStringContainsString('Abschnitt A', $this->trimmed($toc));
    }

    public function testKeepsAnInArticleTableOfContentsNavInsideMain(): void
    {
        // A <nav> that sits inside the article's own <main> is an in-content
        // table of contents, not site chrome; the trimmer leaves it alone.
        $toc = '<nav><a href="https://example.test/a">Abschnitt A</a>'
            . '<a href="https://example.test/b">Abschnitt B</a></nav>';
        $html = '<main>' . $toc . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></main>';

        self::assertStringContainsString('Abschnitt A', $this->trimmed($html));
    }

    public function testLeavesAnArticleWithoutNavigationLandmarksUnchanged(): void
    {
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertSame(2, substr_count($this->trimmed($html), ProseParagraphs::SUBSTANTIAL));
    }

    public function testKeepsAPlainLinkListThatCarriesNoNavigationLandmark(): void
    {
        // Without a landmark, a list under the menu's three-link threshold stays.
        $list = '<ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul>';
        $html = '<div>' . $list . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringContainsString('href="/a"', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuShapedListWithoutALandmark(): void
    {
        // A bare <ul> of outbound, link-only items before the first paragraph is a site menu.
        $menu = '<ul class="side-nav">'
            . '<li><a href="https://d.test/subscribe">Subscribe</a></li>'
            . '<li><a href="https://d.test/magazine">Magazine</a></li>'
            . '<li><a href="https://d.test/online">Online</a></li>'
            . '<li><a href="https://d.test/store">Store</a></li></ul>';
        $html = '<div>' . $menu . '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p></div></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('side-nav', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesALeadingMenuListOfExactlyThreeLinks(): void
    {
        // A three-link breadcrumb is the smallest leading link-only list that counts as a menu.
        $menu = '<ul><li><a href="https://d.test/a">A</a></li>'
            . '<li><a href="https://d.test/b">B</a></li>'
            . '<li><a href="https://d.test/c">C</a></li></ul>';
        $html = '<div>' . $menu . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('href="https://d.test/a"', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testKeepsALeadingListWithFewerThanThreeLinks(): void
    {
        $menu = '<ul><li><a href="https://d.test/a">A</a></li>'
            . '<li><a href="https://d.test/b">B</a></li></ul>';
        $html = '<div>' . $menu . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringContainsString('href="https://d.test/a"', $this->trimmed($html));
    }

    public function testKeepsAnInPageTableOfContentsList(): void
    {
        // Every item is an in-page (#) link — the article's own affordance.
        $toc = '<ul><li><a href="#one">One</a></li><li><a href="#two">Two</a></li>'
            . '<li><a href="#three">Three</a></li><li><a href="#four">Four</a></li></ul>';
        $html = '<div>' . $toc . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringContainsString('#one', $this->trimmed($html));
    }

    public function testKeepsAMenuShapedListThatFollowsTheFirstParagraph(): void
    {
        // After the article started, a link list is "further reading", not chrome.
        $menu = '<ul><li><a href="https://d.test/a">A</a></li>'
            . '<li><a href="https://d.test/b">B</a></li><li><a href="https://d.test/c">C</a></li>'
            . '<li><a href="https://d.test/d">D</a></li></ul>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p>' . $menu . '</div>';

        self::assertStringContainsString('href="https://d.test/a"', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuListWhenTheFollowingParagraphIsJustUnderTheProseThreshold(): void
    {
        // 119 non-link chars, one under SUBSTANTIAL_PROSE_LENGTH: no paragraph
        // in the document qualifies, so the menu still counts as leading.
        $menu = $this->fourLinkMenu();
        $shortParagraph = str_repeat('x', 119);
        $html = '<div>' . $menu . '<p>' . $shortParagraph . '</p></div>';

        self::assertStringNotContainsString('d.test/a', $this->trimmed($html));
    }

    public function testKeepsAMenuListFollowingAParagraphAtExactlyTheProseThreshold(): void
    {
        // 120 non-link chars meets SUBSTANTIAL_PROSE_LENGTH: the article has
        // started, so a menu-shaped list after it is "further reading", kept.
        $paragraph = str_repeat('x', 120);
        $menu = $this->fourLinkMenu();
        $html = '<div><p>' . $paragraph . '</p>' . $menu . '</div>';

        self::assertStringContainsString('d.test/a', $this->trimmed($html));
    }

    public function testKeepsAMenuListBetweenAParagraphAtExactlyTheThresholdAndALaterOne(): void
    {
        // A first paragraph of exactly SUBSTANTIAL_PROSE_LENGTH anchors, so the list after it is not leading.
        $paragraphAtThreshold = str_repeat('x', 120);
        $menu = $this->fourLinkMenu();
        $html = '<div><p>' . $paragraphAtThreshold . '</p>' . $menu . '<p>' . ProseParagraphs::SUBSTANTIAL
            . '</p></div>';

        self::assertStringContainsString('d.test/a', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuListWhenTheFirstParagraphIsShortInCharactersButLongInBytes(): void
    {
        // 65 "ü" are 130 bytes but 65 characters, under the bar: the anchor is the later paragraph, so the list
        // between them is a leading menu.
        $shortMultibyteParagraph = str_repeat('ü', 65);
        $menu = $this->fourLinkMenu();
        $html = '<div><p>' . $shortMultibyteParagraph . '</p>' . $menu . '<p>' . ProseParagraphs::SUBSTANTIAL
            . '</p></div>';

        self::assertStringNotContainsString('d.test/a', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuListAtExactlyTheLinkTextRatioThreshold(): void
    {
        // Each item is "AAA" (link) + "BB" (plain): 12 link chars of 20 total,
        // exactly the 0.6 LINK_TEXT_RATIO threshold — still chrome.
        $list = $this->fourItemList('BB');
        $html = '<div>' . $list . '</div>';

        self::assertStringNotContainsString('d.test/a', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuListAtExactlyTheLinkTextRatioThresholdWithMultibyteText(): void
    {
        // "üü" is 2 characters but 4 bytes: by characters each item sits exactly at the 0.6 ratio, still chrome;
        // a byte count would fall below it.
        $list = $this->fourItemList('üü');
        $html = '<div>' . $list . '</div>';

        self::assertStringNotContainsString('d.test/a', $this->trimmed($html));
    }

    public function testKeepsALeadingListJustBelowTheLinkTextRatioThreshold(): void
    {
        // Each item is "AAA" (link) + "BBB" (plain): 12 link chars of 24
        // total, below the 0.6 threshold — no longer link-dominated, kept.
        $list = $this->fourItemList('BBB');
        $html = '<div>' . $list . '</div>';

        self::assertStringContainsString('d.test/a', $this->trimmed($html));
    }

    public function testKeepsATrailingMenuListInAPostWithNoSubstantialProse(): void
    {
        // With no substantial prose the anchor falls back to the first short paragraph, so a trailing list is not
        // mistaken for a masthead.
        $shortParagraph = '<p>' . str_repeat('x', 40) . '</p>';
        $menu = $this->fourLinkMenu();
        $html = '<div>' . $shortParagraph . $shortParagraph . $menu . '</div>';

        self::assertStringContainsString('d.test/a', $this->trimmed($html));
    }

    public function testRemovesALeadingMenuListFromAPageWithNoParagraphAtAll(): void
    {
        // Div-soup with no <p>/heading anywhere: no prose candidate exists, so
        // the anchor is null and the menu counts as leading regardless of position.
        $menu = $this->fourLinkMenu();
        $bodyText = str_repeat('y', 150);
        $html = '<div>' . $menu . '<div>' . $bodyText . '</div></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('d.test/a', $result);
        self::assertStringContainsString($bodyText, $result);
    }

    private function fourLinkMenu(): string
    {
        return '<ul><li><a href="https://d.test/a">A</a></li>'
            . '<li><a href="https://d.test/b">B</a></li>'
            . '<li><a href="https://d.test/c">C</a></li>'
            . '<li><a href="https://d.test/d">D</a></li></ul>';
    }

    private function fourItemList(string $trailingText): string
    {
        $item = static fn (string $path): string => '<li><a href="https://d.test/' . $path . '">AAA</a>'
            . $trailingText . '</li>';

        return '<ul>' . $item('a') . $item('b') . $item('c') . $item('d') . '</ul>';
    }

    private function trimmed(string $bodyHtml): string
    {
        $document = $this->document($bodyHtml);

        $this->trimmer->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
