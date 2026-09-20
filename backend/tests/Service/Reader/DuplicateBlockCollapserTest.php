<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\Provider\VimeoEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class DuplicateBlockCollapserTest extends TestCase
{
    private DuplicateBlockCollapser $collapser;

    protected function setUp(): void
    {
        $this->collapser = new DuplicateBlockCollapser(
            new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]),
        );
    }

    /** A responsive layout emits the dek once per breakpoint; the scraper keeps both copies. */
    public function testRemovesAParagraphThatRepeatsThePrecedingParagraph(): void
    {
        $html = $this->collapsed(
            '<div><p>Apple might recycle the name.</p></div>'
            . '<div><p>Apple might recycle the name.</p></div>'
        );

        self::assertSame(1, substr_count($html, 'Apple might recycle the name.'));
    }

    public function testKeepsTwoDifferentConsecutiveParagraphs(): void
    {
        $html = $this->collapsed('<p>First sentence.</p><p>Second sentence.</p>');

        self::assertStringContainsString('First sentence.', $html);
        self::assertStringContainsString('Second sentence.', $html);
    }

    /** A different paragraph between two equal ones breaks the chain, so neither is a duplicate of its predecessor. */
    public function testKeepsARepeatedParagraphSeparatedByADifferentOne(): void
    {
        $html = $this->collapsed('<p>Same line.</p><p>Other line.</p><p>Same line.</p>');

        self::assertSame(2, substr_count($html, 'Same line.'));
    }

    /** The dek repeats once per breakpoint; only the dek is collapsed now, the lead image is kept (#1088). */
    public function testCollapsesTheResponsiveDuplicateDek(): void
    {
        $html = $this->collapsed(
            '<div><div><p>Apple might recycle the name.</p></div>'
            . '<div><div><p>Apple might recycle the name.</p></div>'
            . '<div><div><p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p></div>'
            . '<p><cite>Image: Cath Virginia / The Verge</cite></p></div></div></div></div>'
        );

        self::assertSame(1, substr_count($html, 'Apple might recycle the name.'));
        self::assertStringContainsString('Cath Virginia', $html);
    }

    /**
     * Image de-duplication was removed as too URL-fingerprint-fragile: it deleted
     * distinct photos that shared only a generic filename (#1032 Pixabay, #1051
     * YouTube posters, #1088 CBC). A responsive duplicate image is now kept, not
     * collapsed — showing one twice beats deleting the wrong one.
     */
    public function testKeepsAResponsiveDuplicateImage(): void
    {
        $html = $this->collapsed(
            '<p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p>'
        );

        self::assertSame(2, substr_count($html, '<img'));
    }

    /** A paragraph that wraps an image is never collapsed by its text: image blocks are out of the prose comparison. */
    public function testKeepsTwoParagraphsThatWrapImagesUnderTheSameText(): void
    {
        $html = $this->collapsed(
            '<p>Gallery <img src="https://x.test/pic-alpha-11111.jpg" alt="a"></p>'
            . '<p>Gallery <img src="https://x.test/pic-bravo-22222.jpg" alt="b"></p>'
        );

        self::assertSame(2, substr_count($html, 'Gallery'));
        self::assertSame(2, substr_count($html, '<img'));
    }

    /** A blank line is spacing, not content; two of them are not a duplicate to collapse. */
    public function testKeepsConsecutiveBlankParagraphs(): void
    {
        $html = $this->collapsed('<p> </p><p> </p>');

        self::assertSame(2, substr_count($html, '<p'));
    }

    /**
     * A posterless embed (Vimeo, SoundCloud, Brightcove) recovers as a bare
     * `<a>` carrying the provider's fixed label, so two in a row read as
     * identical prose. The paragraph path must spare them (#1051).
     */
    public function testKeepsTwoPosterlessEmbedsThatCarryTheSameLabel(): void
    {
        $html = $this->collapsed(
            '<p><a href="https://player.vimeo.com/video/111111111">Watch on Vimeo</a></p>'
            . '<p><a href="https://player.vimeo.com/video/222222222">Watch on Vimeo</a></p>'
        );

        self::assertStringContainsString('111111111', $html);
        self::assertStringContainsString('222222222', $html);
    }

    /** Surrounding whitespace is not a difference: the dek repeats and one copy goes. */
    public function testCollapsesDeksThatDifferOnlyInSurroundingWhitespace(): void
    {
        $html = $this->collapsed('<p>Apple recycle.</p><p>  Apple recycle.  </p>');

        self::assertSame(1, substr_count($html, 'Apple recycle.'));
    }

    /** Case folds across multibyte letters too, so an all-caps accented dek still reads as the same line. */
    public function testCollapsesDeksThatDifferOnlyInMultibyteLetterCase(): void
    {
        $html = $this->collapsed('<p>Éclair news.</p><p>éclair news.</p>');

        self::assertSame(1, substr_count($html, 'clair news.'));
    }

    /** A structural section is never dissolved, even when a collapse leaves it empty. */
    public function testKeepsAStructuralSectionLeftEmptyByACollapse(): void
    {
        $html = $this->collapsed(
            '<section><p>Same dek line.</p></section><section><p>Same dek line.</p></section>'
        );

        self::assertSame(1, substr_count($html, 'Same dek line.'));
        self::assertSame(2, substr_count($html, '<section'));
    }

    private function collapsed(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->collapser->collapseIn($document);

        return $document->saveHtml();
    }
}
