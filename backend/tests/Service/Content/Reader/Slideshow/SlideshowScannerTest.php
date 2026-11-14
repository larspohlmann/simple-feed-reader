<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowRecognizer\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowRecognizer\TagesschauCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class SlideshowScannerTest extends TestCase
{
    use ParsesHtml;

    public function testScanCollectsFromEveryRecognizer(): void
    {
        $scanner = new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
        $document = $this->document(
            '<body><p>A paragraph long enough to serve as the gallery anchor here.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '<div class="swiper-slide"><img src="https://img/b.jpg" alt="B"></div>'
            . '</div></div></body>',
        );

        $shows = $scanner->scan($document);

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }
}
