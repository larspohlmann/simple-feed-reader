<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use PHPUnit\Framework\TestCase;

final class SlideshowScannerTest extends TestCase
{
    public function testScanCollectsFromEveryRecognizer(): void
    {
        $scanner = new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
        $document = HtmlDocumentParser::parseOrNull(
            '<body><p>A paragraph long enough to serve as the gallery anchor here.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '<div class="swiper-slide"><img src="https://img/b.jpg" alt="B"></div>'
            . '</div></div></body>',
        );
        self::assertNotNull($document);

        $shows = $scanner->scan($document);

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }
}
