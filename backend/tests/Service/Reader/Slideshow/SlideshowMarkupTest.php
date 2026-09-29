<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Reader\Slideshow\Model\SlideCaptionModel;
use App\Service\Reader\Slideshow\Model\SlideModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use PHPUnit\Framework\TestCase;

final class SlideshowMarkupTest extends TestCase
{
    public function testBuildsFigureWithCaptionAndLazyImages(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = SlideshowModel::fromSlides(
            [new SlideModel('https://img/1.jpg', 'first chart'), new SlideModel('https://img/2.jpg', 'second chart')],
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
        $show = SlideshowModel::fromSlides(
            [new SlideModel('https://img/1.jpg', 'a'), new SlideModel('https://img/2.jpg', 'b')],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);

        self::assertStringNotContainsString('<figcaption>', $document->saveHtml());
    }

    public function testRendersALinkedCaptionAsAnAnchorAndAPlainOneAsAParagraph(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = SlideshowModel::fromSlides(
            [
                new SlideModel(
                    'https://img/1.jpg',
                    'a',
                    new SlideCaptionModel('Linked headline', 'https://example.com/one'),
                ),
                new SlideModel('https://img/2.jpg', 'b', new SlideCaptionModel('Plain headline', null)),
            ],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);
        $html = $document->saveHtml();

        self::assertStringContainsString('<a href="https://example.com/one">Linked headline</a>', $html);
        self::assertStringContainsString('<p>Plain headline</p>', $html);
    }

    public function testOmitsAnEmptyCaption(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = SlideshowModel::fromSlides(
            [new SlideModel('https://img/1.jpg', 'a'), new SlideModel('https://img/2.jpg', 'b')],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $figure = (new SlideshowMarkup())->figureFor($document, $show);
        $document->body?->appendChild($figure);
        $html = $document->saveHtml();

        self::assertStringNotContainsString('<p>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testOnlyTheFirstOfThreeSlidesLoadsEagerly(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body></body>');
        self::assertNotNull($document);
        $show = SlideshowModel::fromSlides(
            [
                new SlideModel('https://img/1.jpg', 'a'),
                new SlideModel('https://img/2.jpg', 'b'),
                new SlideModel('https://img/3.jpg', 'c'),
            ],
            null,
            null,
            null,
        );
        self::assertNotNull($show);

        $document->body?->appendChild((new SlideshowMarkup())->figureFor($document, $show));
        $html = $document->saveHtml();

        self::assertSame(1, substr_count($html, 'loading="eager"'));
        self::assertSame(2, substr_count($html, 'loading="lazy"'));
        self::assertStringContainsString('src="https://img/1.jpg" alt="a" loading="eager"', $html);
    }
}
