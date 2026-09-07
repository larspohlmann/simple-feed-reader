<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class SlideCaptionResolverTest extends TestCase
{
    public function testReadsVisibleTextAndTheFirstAbsoluteLink(): void
    {
        $slide = $this->slide(
            '<li class="swiper-slide"><a href="https://example.com/other">'
            . '<img src="https://img/1.jpg" alt="an image"><h3>Head</h3> line</a></li>',
        );

        $caption = (new SlideCaptionResolver())->resolve($slide);

        self::assertSame('Head line', $caption->text);
        self::assertSame('https://example.com/other', $caption->link);
        self::assertTrue($caption->hasLink());
        self::assertFalse($caption->isEmpty());
    }

    public function testIgnoresScriptTextInTheCaption(): void
    {
        $slide = $this->slide(
            '<li class="swiper-slide"><script>{"noise":"ignore me"}</script>Only this</li>',
        );

        self::assertSame('Only this', (new SlideCaptionResolver())->resolve($slide)->text);
    }

    public function testHasNoLinkForRelativeOrMissingHref(): void
    {
        $slide = $this->slide('<li class="swiper-slide"><a href="/local">Text</a></li>');

        $caption = (new SlideCaptionResolver())->resolve($slide);

        self::assertNull($caption->link);
        self::assertFalse($caption->hasLink());
    }

    public function testSkipsANonHttpAnchorForTheNextAbsoluteOne(): void
    {
        $slide = $this->slide(
            '<li class="swiper-slide"><a href="#top">Skip</a>'
            . '<a href="https://example.com/real">Real</a></li>',
        );

        self::assertSame('https://example.com/real', (new SlideCaptionResolver())->resolve($slide)->link);
    }

    public function testEmptyWhenSlideHasNoText(): void
    {
        $slide = $this->slide('<li class="swiper-slide"><img src="https://img/1.jpg" alt="x"></li>');

        self::assertTrue((new SlideCaptionResolver())->resolve($slide)->isEmpty());
    }

    private function slide(string $html): Element
    {
        $document = HtmlDocumentParser::parseOrNull('<body>' . $html . '</body>');
        self::assertNotNull($document);
        $slide = $document->querySelector('.swiper-slide');
        self::assertInstanceOf(Element::class, $slide);

        return $slide;
    }
}
