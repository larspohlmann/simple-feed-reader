<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ImageWrapperClassRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ImageWrapperClassRemoverTest extends TestCase
{
    private ImageWrapperClassRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ImageWrapperClassRemover();
    }

    /** nature.com 495343: readability weights `ResponsiveMedia` −25 and removes the text-less wrapper, photo included. */
    public function testStripsClassAndIdFromTextlessSingleImageWrappers(): void
    {
        $html = $this->stripped(
            '<div id="section-x" class="Theme-Section"><div class="Theme-Layer-ResponsiveMedia">'
            . '<div class="ResponsiveMedia--image__inner" id="inner">'
            . '<img src="https://x.test/a.jpg" alt=""></div></div>'
            . '<p>Caption.</p></div>'
        );

        self::assertStringContainsString(
            '<div id="section-x" class="Theme-Section"><div><div><img src="https://x.test/a.jpg" alt=""></div></div>',
            $html,
        );
    }

    public function testStopsAtAWrapperThatCarriesText(): void
    {
        $html = $this->stripped(
            '<figure class="InlineMedia"><div class="InlineMedia--image__inner">'
            . '<img src="https://x.test/a.jpg" alt=""></div>'
            . '<figcaption class="Theme-Caption">Luca Bindi et al.</figcaption></figure>'
        );

        self::assertStringContainsString('<figure class="InlineMedia"><div><img', $html);
        self::assertStringContainsString('<figcaption class="Theme-Caption">', $html);
    }

    public function testLeavesAWrapperThatHoldsMoreThanOneImage(): void
    {
        $html = $this->stripped(
            '<div class="MediaGallery_carousel"><div class="cell"><img src="https://x.test/a.jpg" alt=""></div>'
            . '<div class="cell"><img src="https://x.test/b.jpg" alt=""></div></div>'
        );

        self::assertStringContainsString('<div class="MediaGallery_carousel"><div><img', $html);
        self::assertStringContainsString('</div><div><img src="https://x.test/b.jpg"', $html);
    }

    public function testNeverTouchesTheImageItselfOrTheBody(): void
    {
        $html = $this->stripped('<img class="FullSize lazy" id="hero" src="https://x.test/a.jpg" alt="">');

        self::assertStringContainsString('<body class="page"><img class="FullSize lazy" id="hero"', $html);
    }

    public function testATextWrapperKeepsItsClassWhenTheImageIsInline(): void
    {
        $html = $this->stripped('<p class="lead">Text <img src="https://x.test/i.png" alt=""> more</p>');

        self::assertStringContainsString('<p class="lead">', $html);
    }

    /** treehugger: a sidebar card's thumbnail sits in a link; readability drops the card by its `media` class, and must keep doing so. */
    public function testLeavesALinkedCardThumbnailAlone(): void
    {
        $html = $this->stripped(
            '<a class="card" href="https://x.test/other"><div class="card__media"><div class="img-placeholder">'
            . '<img src="https://x.test/thumb.jpg" alt=""></div></div></a>'
        );

        self::assertStringContainsString('<div class="card__media"><div class="img-placeholder">', $html);
    }

    public function testLeavesAnImageInsidePageFurnitureAlone(): void
    {
        $html = $this->stripped(
            '<aside><div class="teaser-media"><img src="https://x.test/t.jpg" alt=""></div></aside>',
        );

        self::assertStringContainsString('<div class="teaser-media">', $html);
    }

    private function stripped(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body class="page">' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
