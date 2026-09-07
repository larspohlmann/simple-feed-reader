<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\Slideshow;
use PHPUnit\Framework\TestCase;

final class MarkupCarouselRecognizerTest extends TestCase
{
    /** @return list<Slideshow> */
    private function recognize(string $html): array
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return (new MarkupCarouselRecognizer(new SlideImageResolver()))
            ->recognize($document, PageTextBlocks::fromDocument($document));
    }

    public function testDetectsSwiperWithRealImages(): void
    {
        $shows = $this->recognize(
            '<body><p>An intro paragraph long enough to anchor the gallery below.</p>'
            . '<div class="swiper"><div class="swiper-wrapper">'
            . '<a class="swiper-slide" data-src="https://img/a.jpg"><img src="https://img/a.jpg" alt="A"></a>'
            . '<a class="swiper-slide" data-src="https://img/b.jpg"><img src="https://img/b.jpg" alt="B"></a>'
            . '</div></div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
        self::assertSame('https://img/a.jpg', $shows[0]->slides[0]->imageUrl);
        self::assertSame('An intro paragraph long enough to anchor the gallery below.', $shows[0]->precedingText);
    }

    public function testDetectsOwlByContainerNotItemClass(): void
    {
        $shows = $this->recognize(
            '<body><div class="owl-carousel owl-theme">'
            . '<a class="owl-carousel-item" data-src="https://img/1.jpg">x</a>'
            . '<a class="owl-carousel-item" data-src="https://img/2.jpg">y</a>'
            . '</div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }

    public function testDetectsFlickityByCellGroupedByParent(): void
    {
        $shows = $this->recognize(
            '<body><div class="main-carousel">'
            . '<div class="carousel-cell"><img src="https://img/1.jpg" alt="1"></div>'
            . '<div class="carousel-cell"><img src="https://img/2.jpg" alt="2"></div>'
            . '</div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }

    public function testAbstainsOnImagelessGlideFrames(): void
    {
        self::assertSame([], $this->recognize(
            '<body><div class="glide"><ul class="glide__slides">'
            . '<li class="glide__slide"><div class="frame"></div></li>'
            . '<li class="glide__slide"><div class="frame"></div></li>'
            . '</ul></div></body>',
        ));
    }

    public function testAbstainsOnASingleImageSlide(): void
    {
        self::assertSame([], $this->recognize(
            '<body><div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide"><img src="https://img/a.jpg" alt="A"></div>'
            . '</div></div></body>',
        ));
    }
}
