<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\SlideImageResolver;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class SlideImageResolverTest extends TestCase
{
    private function slide(string $inner): Element
    {
        $document = HtmlDocumentParser::parseOrNull('<body><div class="slide">' . $inner . '</div></body>');
        self::assertNotNull($document);
        $slide = $document->querySelector('.slide');
        self::assertNotNull($slide);

        return $slide;
    }

    /**
     * @param list<string> $inners
     *
     * @return list<Element>
     */
    private function slides(array $inners): array
    {
        $body = '';
        foreach ($inners as $inner) {
            $body .= '<div class="slide">' . $inner . '</div>';
        }
        $document = HtmlDocumentParser::parseOrNull('<body>' . $body . '</body>');
        self::assertNotNull($document);

        $slides = [];
        foreach ($document->querySelectorAll('.slide') as $slide) {
            $slides[] = $slide;
        }

        return $slides;
    }

    public function testPrefersARealImgSrc(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<img src="https://img/1.jpg">'));
        self::assertSame('https://img/1.jpg', $url);
    }

    public function testFallsBackToDataSrc(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<a data-src="https://img/2.jpg">x</a>'));
        self::assertSame('https://img/2.jpg', $url);
    }

    public function testFallsBackToAnchorHrefImage(): void
    {
        $url = (new SlideImageResolver())->resolve($this->slide('<a href="https://img/3.webp">x</a>'));
        self::assertSame('https://img/3.webp', $url);
    }

    public function testNullWhenNoImage(): void
    {
        self::assertNull((new SlideImageResolver())->resolve($this->slide('<p>only text</p>')));
    }

    public function testResolveAllReturnsEachSlidesFirstImage(): void
    {
        $urls = (new SlideImageResolver())->resolveAll($this->slides([
            '<img alt="A" src="https://img/a.jpg">',
            '<img alt="B" src="https://img/b.jpg">',
        ]));

        self::assertSame(['https://img/a.jpg', 'https://img/b.jpg'], $urls);
    }

    public function testResolveAllRecoversRealImagesBehindARepeatedPlaceholderSrc(): void
    {
        $urls = (new SlideImageResolver())->resolveAll($this->slides([
            '<img alt="A" src="https://cdn/ph.cms" data-src="https://img/real-a.jpg">',
            '<img alt="B" src="https://cdn/ph.cms" data-src="https://img/real-b.jpg">',
        ]));

        self::assertSame(['https://img/real-a.jpg', 'https://img/real-b.jpg'], $urls);
    }

    public function testResolveAllYieldsNullWhereOnlyThePlaceholderExists(): void
    {
        $urls = (new SlideImageResolver())->resolveAll($this->slides([
            '<a href="https://site.test/videoshow/1.cms"><img alt="P" src="https://cdn/ph.cms"></a>',
            '<a href="https://site.test/videoshow/2.cms"><img alt="P" src="https://cdn/ph.cms"></a>',
        ]));

        self::assertSame([null, null], $urls);
    }

    public function testResolveAllRecoversTheResolvableSlidesAndKeepsAnImagelessSlideNull(): void
    {
        $urls = (new SlideImageResolver())->resolveAll($this->slides([
            '<p>no image here</p>',
            '<img alt="A" src="https://cdn/ph.cms" data-src="https://img/a.jpg">',
            '<img alt="B" src="https://cdn/ph.cms" data-src="https://img/b.jpg">',
        ]));

        self::assertSame([null, 'https://img/a.jpg', 'https://img/b.jpg'], $urls);
    }

    public function testResolveAllDoesNotTreatALoneImageAsAPlaceholder(): void
    {
        $urls = (new SlideImageResolver())->resolveAll($this->slides([
            '<img alt="A" src="https://img/a.jpg" data-src="https://img/a-large.jpg">',
            '<p>no image here</p>',
        ]));

        self::assertSame(['https://img/a.jpg', null], $urls);
    }
}
