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
}
