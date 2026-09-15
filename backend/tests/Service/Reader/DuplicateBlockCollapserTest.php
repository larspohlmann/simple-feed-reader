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

    /** The desktop and mobile <img> point at different renditions of one photo. */
    public function testRemovesAnImageThatRepeatsThePrecedingImageOfTheSameAsset(): void
    {
        $html = $this->collapsed(
            '<p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p>'
        );

        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('w=2400', $html);
    }

    /** The paragraph wrapping the removed duplicate image must go with it, not linger empty. */
    public function testRemovesTheEmptyParagraphLeftByARemovedDuplicateImage(): void
    {
        $html = $this->collapsed(
            '<div><p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p></div>'
        );

        self::assertSame(1, substr_count($html, '<p'));
    }

    public function testKeepsTwoDifferentConsecutiveParagraphs(): void
    {
        $html = $this->collapsed('<p>First sentence.</p><p>Second sentence.</p>');

        self::assertStringContainsString('First sentence.', $html);
        self::assertStringContainsString('Second sentence.', $html);
    }

    public function testKeepsTwoDifferentConsecutiveImages(): void
    {
        $html = $this->collapsed(
            '<p><img src="https://x.test/photo-alpha-12345.jpg" alt="Alpha"></p>'
            . '<p><img src="https://x.test/photo-bravo-67890.jpg" alt="Bravo"></p>'
        );

        self::assertSame(2, substr_count($html, '<img'));
    }

    /** A different paragraph between two equal ones breaks the chain, so neither is a duplicate of its predecessor. */
    public function testKeepsARepeatedParagraphSeparatedByADifferentOne(): void
    {
        $html = $this->collapsed('<p>Same line.</p><p>Other line.</p><p>Same line.</p>');

        self::assertSame(2, substr_count($html, 'Same line.'));
    }

    /** The real shape: the source repeats both the dek and the lead image; each must survive once. */
    public function testCollapsesAResponsiveLeadThatRepeatsBothDekAndImage(): void
    {
        $html = $this->collapsed(
            '<div><div><p>Apple might recycle the name.</p></div>'
            . '<div><div><p>Apple might recycle the name.</p></div>'
            . '<div><div><p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=828" alt="Apple event"></p></div>'
            . '<p><cite>Image: Cath Virginia / The Verge</cite></p></div></div></div></div>'
        );

        self::assertSame(1, substr_count($html, 'Apple might recycle the name.'));
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('Cath Virginia', $html);
    }

    /** A blank line is spacing, not content; two of them are not a duplicate to collapse. */
    public function testKeepsConsecutiveBlankParagraphs(): void
    {
        $html = $this->collapsed('<p> </p><p> </p>');

        self::assertSame(2, substr_count($html, '<p'));
    }

    /** A paragraph that wraps a distinct image is compared as an image, never by its shared caption text. */
    public function testKeepsTwoParagraphsThatWrapDifferentImagesUnderTheSameText(): void
    {
        $html = $this->collapsed(
            '<p>Gallery <img src="https://x.test/pic-alpha-11111.jpg" alt="a"></p>'
            . '<p>Gallery <img src="https://x.test/pic-bravo-22222.jpg" alt="b"></p>'
        );

        self::assertSame(2, substr_count($html, 'Gallery'));
        self::assertSame(2, substr_count($html, '<img'));
    }

    /**
     * Two different stock photos whose filenames share only provenance words
     * (the library, a batch date, "download") are distinct images, not a
     * responsive duplicate — the reader must keep both (#1032, Utopia/Pixabay).
     */
    public function testKeepsTwoDistinctStockPhotosThatShareOnlyProvenanceTokens(): void
    {
        $html = $this->collapsed(
            '<figure><img src="https://x.test/wreath-cc0-pixabay-couleur-260905-download.jpg" alt="a"></figure>'
            . '<figure><img src="https://x.test/leaves-cc0-pixabay-hans-260905-download.jpg" alt="b"></figure>'
        );

        self::assertSame(2, substr_count($html, '<img'));
    }

    /**
     * The reader recovers each in-body video as an embed link with a poster; every
     * YouTube poster's filename is the fixed quality label `hqdefault.jpg`, so the
     * posters share a stem though the videos differ. They are distinct media
     * anchors, not a responsive-duplicate image, and both must survive (#1051).
     */
    public function testKeepsThePostersOfTwoDistinctRecoveredEmbeds(): void
    {
        $html = $this->collapsed(
            '<p><a href="https://www.youtube-nocookie.com/embed/GAq34QMhzEM">'
            . '<img src="https://i.ytimg.com/vi/GAq34QMhzEM/hqdefault.jpg" alt="Watch on YouTube"></a></p>'
            . '<p><a href="https://www.youtube-nocookie.com/embed/_jW8hlXNQmY">'
            . '<img src="https://i.ytimg.com/vi/_jW8hlXNQmY/hqdefault.jpg" alt="Watch on YouTube"></a></p>'
        );

        self::assertSame(2, substr_count($html, '<img'));
        self::assertStringContainsString('GAq34QMhzEM', $html);
        self::assertStringContainsString('_jW8hlXNQmY', $html);
    }

    /**
     * A posterless embed (Vimeo, SoundCloud, Brightcove) recovers as a bare
     * `<a>` carrying the provider's fixed label, so two in a row read as
     * identical prose. The paragraph path must spare them too (#1051).
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

    /** A responsive-duplicate image linked to its own full-size file is still a duplicate; only recovered embeds are spared. */
    public function testStillCollapsesADuplicateImageLinkedToItsOwnFullSizeFile(): void
    {
        $html = $this->collapsed(
            '<p><a href="https://x.test/stk071-apple-b.jpg">'
            . '<img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="e"></a></p>'
            . '<p><a href="https://x.test/stk071-apple-b.jpg">'
            . '<img src="https://x.test/stk071-apple-b.jpg?w=828" alt="e"></a></p>'
        );

        self::assertSame(1, substr_count($html, '<img'));
    }

    /** The wrapper that only spaced the removed image must go with it, whitespace and all. */
    public function testRemovesAWhitespaceOnlyParagraphLeftByARemovedDuplicateImage(): void
    {
        $html = $this->collapsed(
            '<div><p> <img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="e"> </p>'
            . '<p> <img src="https://x.test/stk071-apple-b.jpg?w=828" alt="e"> </p></div>'
        );

        self::assertSame(1, substr_count($html, '<p'));
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
