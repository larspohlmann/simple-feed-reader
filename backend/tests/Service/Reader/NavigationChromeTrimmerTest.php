<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\NavigationChromeTrimmer;
use PHPUnit\Framework\TestCase;

final class NavigationChromeTrimmerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private NavigationChromeTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new NavigationChromeTrimmer();
    }

    public function testRemovesASiteHeaderRegionThatReadabilityKeptBeforeTheArticle(): void
    {
        // The Avada/Fusion theme (#verfassungsblog) builds its masthead from
        // content <div>s, so readability scores it as article content. The nav
        // landmark anchors the region; the whole link-dominated header block —
        // logo, an un-rendered search widget and the menu — is chrome.
        $header = '<div class="site-header">'
            . '<a href="https://example.test/"><img src="https://example.test/logo.svg" alt="Logo"></a>'
            . '<div role="dialog"><p>Results for {phrase}</p></div>'
            . '<nav><ul><li><a href="/a">Editorial</a></li><li><a href="/b">Blog</a></li>'
            . '<li><a href="/c">Debate</a></li><li><a href="/d">Books</a></li>'
            . '<li><a href="/e">About</a></li><li><a href="/f">Newsletter</a></li></ul></nav>'
            . '</div>';
        $html = '<div id="wrap">' . $header . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('site-header', $result);
        self::assertStringNotContainsString('{phrase}', $result);
        self::assertStringContainsString(self::PROSE, $result);
    }

    public function testRemovesALeadingHeaderElement(): void
    {
        $header = '<header><a href="/a">Editorial</a><a href="/b">Blog</a>'
            . '<a href="/c">Debate</a></header>';
        $html = '<div>' . $header . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('Editorial', $result);
        self::assertStringContainsString(self::PROSE, $result);
    }

    public function testRemovesALeadingRoleNavigationRegionCaseInsensitively(): void
    {
        // The role match lower-cases first, so a capitalised role still counts.
        $nav = '<div role="Navigation"><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a></div>';
        $html = '<div>' . $nav . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('Editorial', $result);
        self::assertStringContainsString(self::PROSE, $result);
    }

    public function testRemovesEveryNavigationRegionNotJustTheFirst(): void
    {
        $first = '<div class="nav-one"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a></nav></div>';
        $second = '<div class="nav-two"><nav><a href="/d">Jobs</a>'
            . '<a href="/e">About</a><a href="/f">Books</a></nav></div>';
        $html = '<div>' . $first . '<main><p>' . self::PROSE . '</p></main>' . $second . '</div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('nav-one', $result);
        self::assertStringNotContainsString('nav-two', $result);
        self::assertStringContainsString(self::PROSE, $result);
    }

    public function testKeepsAnInArticleNavYetStillRemovesLaterChrome(): void
    {
        // The in-article nav is skipped, not a stopping point: a chrome region
        // that comes after it in document order is still removed.
        $toc = '<main><nav class="toc"><a href="https://example.test/x">Kapitel</a></nav>'
            . '<p>' . self::PROSE . '</p></main>';
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
            . '<p>' . self::PROSE . '</p></article>';

        self::assertStringContainsString('Abschnitt A', $this->trimmed($toc));
    }

    public function testKeepsAnInArticleTableOfContentsNavInsideMain(): void
    {
        // A <nav> that sits inside the article's own <main> is an in-content
        // table of contents, not site chrome; the trimmer leaves it alone.
        $toc = '<nav><a href="https://example.test/a">Abschnitt A</a>'
            . '<a href="https://example.test/b">Abschnitt B</a></nav>';
        $html = '<main>' . $toc . '<p>' . self::PROSE . '</p></main>';

        self::assertStringContainsString('Abschnitt A', $this->trimmed($html));
    }

    public function testLeavesAnArticleWithoutNavigationLandmarksUnchanged(): void
    {
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>';

        self::assertSame(2, substr_count($this->trimmed($html), self::PROSE));
    }

    public function testKeepsAPlainLinkListThatCarriesNoNavigationLandmark(): void
    {
        // A leading list of links with no <nav>/role landmark is not addressed
        // here — that shape is EdgeBoilerplateTrimmer's job. This step acts only
        // on an explicit navigation landmark, so the list survives.
        $list = '<ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul>';
        $html = '<div>' . $list . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('href="/a"', $this->trimmed($html));
    }

    /**
     * Parses the fragment, runs the in-place trim and serialises the shared
     * document — mirroring the parse-once/serialise-once window ReaderBodyCleaner
     * owns in the pipeline.
     */
    private function trimmed(string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);

        $this->trimmer->trimIn($document);

        return $document->saveHtml();
    }
}
