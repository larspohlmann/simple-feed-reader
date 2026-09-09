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

    public function testReadsEachSlideCaptionFromTheDescriptionAndCredit(): void
    {
        $document = HtmlDocumentParser::parseOrNull($this->carousel([
            [
                'alttext' => 'König Harald V.',
                'title' => 'König Harald V. | via REUTERS',
                'description' => 'Er war Europas ältester amtierender Monarch.',
                'imageUrls' => ['l' => 'https://img/1-l.webp'],
            ],
            [
                'alttext' => 'Kronprinz Harald',
                'title' => 'Kronprinz Harald',
                'description' => 'Der junge Kronprinz.',
                'imageUrls' => ['l' => 'https://img/2-l.webp'],
            ],
        ]));
        self::assertNotNull($document);

        $shows = (new TagesschauCarouselRecognizer())
            ->recognize($document, PageTextBlocks::fromDocument($document));

        self::assertSame(
            'Er war Europas ältester amtierender Monarch. (via REUTERS)',
            $shows[0]->slides[0]->caption->text,
        );
        self::assertSame('Der junge Kronprinz.', $shows[0]->slides[1]->caption->text);
    }

    public function testACreditWithoutADescriptionHasNoLeadingSpace(): void
    {
        $document = HtmlDocumentParser::parseOrNull($this->carousel([
            ['title' => 'König Harald V. | via REUTERS', 'imageUrls' => ['l' => 'https://img/1-l.webp']],
            ['title' => 'Kronprinz Harald | EPA', 'imageUrls' => ['l' => 'https://img/2-l.webp']],
        ]));
        self::assertNotNull($document);

        $shows = (new TagesschauCarouselRecognizer())
            ->recognize($document, PageTextBlocks::fromDocument($document));

        self::assertSame('(via REUTERS)', $shows[0]->slides[0]->caption->text);
    }

    /** @param list<array<string, mixed>> $images */
    private function carousel(array $images): string
    {
        $dataV = htmlspecialchars(
            (string) json_encode(['name' => 'Gallery', 'images' => $images], JSON_THROW_ON_ERROR),
            ENT_QUOTES,
        );

        return '<body><div data-v-type="Carousel" data-v="' . $dataV . '"></div></body>';
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
