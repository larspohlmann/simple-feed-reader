<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\SlideshowScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SlideshowScannerTest injects both recognizers by hand, so it stays green even
 * if the container's 'app.slideshow_recognizer' tag collects nothing — the same
 * silent empty-iterator failure FeedParserWiringTest documents. This drives the
 * REAL container wiring: both recognizer families must resolve through the
 * tagged iterator over one page carrying both gallery shapes.
 */
final class SlideshowScannerWiringTest extends KernelTestCase
{
    public function testScanCollectsFromBothTaggedRecognizers(): void
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(SlideshowScanner::class);
        self::assertInstanceOf(SlideshowScanner::class, $scanner);

        $document = HtmlDocumentParser::parseOrNull($this->pageWithBothGalleries());
        self::assertNotNull($document);

        $shows = $scanner->scan($document);

        self::assertCount(2, $shows);
    }

    private function pageWithBothGalleries(): string
    {
        $tagesschauCarousel = '<div class="v-instance carousel__prerender-height--gallery" data-v-type="Carousel"'
            . ' data-v="{&quot;ratio&quot;:&quot;16x9&quot;,&quot;name&quot;:&quot;Wahlgalerie&quot;,'
            . '&quot;images&quot;:[{&quot;alttext&quot;:&quot;Umfrage eins&quot;,&quot;title&quot;:&quot;Umfrage'
            . ' eins&quot;,&quot;imageUrls&quot;:{&quot;xs&quot;:&quot;https://images.tagesschau.de/1-xs.webp&quot;,'
            . '&quot;l&quot;:&quot;https://images.tagesschau.de/1-l.webp&quot;}},{&quot;alttext&quot;:&quot;Umfrage'
            . ' zwei&quot;,&quot;title&quot;:&quot;Umfrage zwei&quot;,&quot;imageUrls&quot;:'
            . '{&quot;xs&quot;:&quot;https://images.tagesschau.de/2-xs.webp&quot;,'
            . '&quot;l&quot;:&quot;https://images.tagesschau.de/2-l.webp&quot;}}]}"></div>';

        return '<body><p>A paragraph long enough to serve as the swiper gallery anchor here.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '<div class="swiper-slide"><img src="https://img/b.jpg" alt="B"></div>'
            . '</div></div>'
            . '<p>Another paragraph long enough to serve as the tagesschau gallery anchor.</p>'
            . $tagesschauCarousel
            . '</body>';
    }
}
