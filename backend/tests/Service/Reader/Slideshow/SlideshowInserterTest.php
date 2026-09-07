<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slide;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use PHPUnit\Framework\TestCase;

final class SlideshowInserterTest extends TestCase
{
    /** @return list<Slide> */
    private function slides(): array
    {
        return [new Slide('https://img/1.jpg', 'a'), new Slide('https://img/2.jpg', 'b')];
    }

    public function testSeatsAfterTheAnchorAndRemovesTheOriginal(): void
    {
        $document = HtmlDocumentParser::parseOrNull(
            '<body><p>The anchor paragraph that is comfortably past forty characters.</p>'
            . '<div class="swiper broken-original">leftover</div></body>',
        );
        self::assertNotNull($document);
        $show = Slideshow::fromSlides(
            $this->slides(),
            null,
            'The anchor paragraph that is comfortably past forty characters.',
            ContainerSignature::fromClassAttribute('swiper broken-original'),
        );
        self::assertNotNull($show);

        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$show]);
        $html = $document->saveHtml();

        self::assertStringNotContainsString('broken-original', $html);
        self::assertStringContainsString('reader-slideshow', $html);
        // The figure follows the anchor paragraph.
        self::assertLessThan(strpos($html, 'reader-slideshow'), strpos($html, 'anchor paragraph'));
    }

    public function testAppendsAtBodyEndWhenNoAnchorSurvives(): void
    {
        $document = HtmlDocumentParser::parseOrNull('<body><p>short</p></body>');
        self::assertNotNull($document);
        $show = Slideshow::fromSlides($this->slides(), null, 'A heading that did not survive extraction here.', null);
        self::assertNotNull($show);

        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$show]);

        self::assertStringContainsString('reader-slideshow', $document->saveHtml());
    }
}
