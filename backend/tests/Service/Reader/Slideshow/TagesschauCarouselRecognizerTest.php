<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagesschauCarouselRecognizerTest extends TestCase
{
    public function testDecodesTheDataVGallery(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($html);
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        $shows = (new TagesschauCarouselRecognizer())
            ->recognize($document, PageTextBlocks::fromDocument($document));

        self::assertCount(1, $shows);
        $show = $shows[0];
        self::assertCount(3, $show->slides);
        self::assertSame('Die Hauptgründe für das Ergebnis in Sachsen-Anhalt', $show->title);
        self::assertSame('https://images.tagesschau.de/1-l.webp?width=1280', $show->slides[0]->imageUrl);
        self::assertSame('Umfrage eins', $show->slides[0]->alt);
    }

    #[DataProvider('malformedCarouselMarkup')]
    public function testAbstainsWithoutThrowingOnMalformedCarouselData(string $html): void
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        $shows = (new TagesschauCarouselRecognizer())
            ->recognize($document, PageTextBlocks::fromDocument($document));

        self::assertSame([], $shows);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCarouselMarkup(): iterable
    {
        yield 'no data-v attribute' => [
            '<body><div data-v-type="Carousel"></div></body>',
        ];
        yield 'data-v is not valid JSON' => [
            '<body><div data-v-type="Carousel" data-v="not json"></div></body>',
        ];
        yield 'valid JSON lacking images' => [
            '<body><div data-v-type="Carousel" data-v="{&quot;name&quot;:&quot;x&quot;}"></div></body>',
        ];
        yield 'image entry lacking imageUrls' => [
            '<body><div data-v-type="Carousel" data-v="{&quot;images&quot;:['
            . '{&quot;alttext&quot;:&quot;a&quot;},{&quot;alttext&quot;:&quot;b&quot;}]}"></div></body>',
        ];
    }
}
