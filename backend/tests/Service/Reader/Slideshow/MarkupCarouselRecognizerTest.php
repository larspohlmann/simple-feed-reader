<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
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

        return (new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()))
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
        self::assertSame('A', $shows[0]->slides[0]->alt);
        self::assertSame('An intro paragraph long enough to anchor the gallery below.', $shows[0]->precedingText);
    }

    public function testKeepsEachSlidesCaptionTextAndLink(): void
    {
        $shows = $this->recognize(
            '<body><div class="swiper"><div class="swiper-wrapper">'
            . '<a class="swiper-slide" href="https://example.com/one">'
            . '<img src="https://img/a.jpg" alt="A"><h3>First headline</h3></a>'
            . '<a class="swiper-slide" href="https://example.com/two">'
            . '<img src="https://img/b.jpg" alt="B"><h3>Second headline</h3></a>'
            . '</div></div></body>',
        );

        self::assertCount(2, $shows[0]->slides);
        self::assertSame('First headline', $shows[0]->slides[0]->caption->text);
        self::assertSame('https://example.com/one', $shows[0]->slides[0]->caption->link);
        self::assertSame('Second headline', $shows[0]->slides[1]->caption->text);
    }

    public function testPrefersTheSlideTitleOverTheImageAltForTheSlideAlt(): void
    {
        $shows = $this->recognize(
            '<body><div class="swiper"><div class="swiper-wrapper">'
            . '<div class="swiper-slide" title="Slide title"><img src="https://img/a.jpg" alt="Image alt"></div>'
            . '<div class="swiper-slide"><img src="https://img/b.jpg" alt="B"></div>'
            . '</div></div></body>',
        );

        self::assertSame('Slide title', $shows[0]->slides[0]->alt);
    }

    public function testDetectsSplideWithRealImages(): void
    {
        $shows = $this->recognize(
            '<body><div class="splide"><div class="splide__track"><ul class="splide__list">'
            . '<li class="splide__slide"><img src="https://img/a.jpg" alt="A"></li>'
            . '<li class="splide__slide"><img src="https://img/b.jpg" alt="B"></li>'
            . '</ul></div></div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
    }

    public function testDetectsGlideWithRealImages(): void
    {
        $shows = $this->recognize(
            '<body><div class="glide"><div class="glide__track"><ul class="glide__slides">'
            . '<li class="glide__slide"><img src="https://img/a.jpg" alt="A"></li>'
            . '<li class="glide__slide"><img src="https://img/b.jpg" alt="B"></li>'
            . '</ul></div></div></body>',
        );

        self::assertCount(1, $shows);
        self::assertCount(2, $shows[0]->slides);
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
