<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use PHPUnit\Framework\TestCase;

final class SlideshowMarkupTest extends TestCase
{
    public function testBuildsFigureWithCaptionAndLazyImages(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'first chart'), new Slide('https://img/2.jpg', 'second chart')],
            'Poll gallery',
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);
        $html = $document->saveHtml();

        self::assertStringContainsString('<figure class="reader-slideshow">', $html);
        self::assertStringContainsString('<figcaption>Poll gallery</figcaption>', $html);
        self::assertSame(2, substr_count($html, '<li>'));
        self::assertStringContainsString('src="https://img/1.jpg" alt="first chart" loading="eager"', $html);
        self::assertStringContainsString('src="https://img/2.jpg" alt="second chart" loading="lazy"', $html);
    }

    public function testOmitsCaptionWhenNoTitle(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            [new Slide('https://img/1.jpg', 'a'), new Slide('https://img/2.jpg', 'b')],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);

        self::assertStringNotContainsString('<figcaption>', $document->saveHtml());
    }
}
