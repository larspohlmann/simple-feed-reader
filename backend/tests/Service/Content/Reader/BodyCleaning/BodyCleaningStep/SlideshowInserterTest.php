<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\SlideshowInserter;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Reader\Slideshow\Model\SlideModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class SlideshowInserterTest extends TestCase
{
    use ParsesHtml;

    /** @param list<SlideshowModel> $slideshows */
    private function insert(HTMLDocument $document, array $slideshows): void
    {
        (new SlideshowInserter(new SlideshowMarkup()))->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withSlideshows($slideshows)),
        );
    }

    /** @return list<SlideModel> */
    private function slides(): array
    {
        return [new SlideModel('https://img/1.jpg', 'a'), new SlideModel('https://img/2.jpg', 'b')];
    }

    public function testSeatsAfterTheAnchorAndRemovesTheOriginal(): void
    {
        $document = $this->document(
            '<body><p>The anchor paragraph that is comfortably past forty characters.</p>'
            . '<div class="swiper broken-original">leftover</div></body>',
        );
        $show = SlideshowModel::fromSlides(
            $this->slides(),
            null,
            'The anchor paragraph that is comfortably past forty characters.',
            ContainerSignatureModel::fromClassAttribute('swiper broken-original'),
        );
        self::assertNotNull($show);

        $this->insert($document, [$show]);
        $html = $document->saveHtml();

        self::assertStringNotContainsString('broken-original', $html);
        self::assertStringContainsString('reader-slideshow', $html);
        // The figure follows the anchor paragraph.
        self::assertLessThan(strpos($html, 'reader-slideshow'), strpos($html, 'anchor paragraph'));
    }

    public function testAppendsAtBodyEndWhenNoAnchorSurvives(): void
    {
        $document = $this->document('<body><p>short</p></body>');
        $show = SlideshowModel::fromSlides(
            $this->slides(),
            null,
            'A heading that did not survive extraction here.',
            null,
        );
        self::assertNotNull($show);

        $this->insert($document, [$show]);

        self::assertStringContainsString('reader-slideshow', $document->saveHtml());
    }

    public function testPairsEachSlideshowWithItsOwnAnchorWhenTwoShareAContainerSignature(): void
    {
        $firstAnchor = 'The first anchor paragraph that is comfortably past forty characters.';
        $secondAnchor = 'The second anchor paragraph that is comfortably past forty characters.';
        $document = $this->document(
            "<body><p>{$firstAnchor}</p><div class=\"swiper broken\">leftover one</div>"
            . "<p>{$secondAnchor}</p><div class=\"swiper broken\">leftover two</div></body>",
        );
        $signature = ContainerSignatureModel::fromClassAttribute('swiper broken');
        $first = SlideshowModel::fromSlides($this->slides(), null, $firstAnchor, $signature);
        $second = SlideshowModel::fromSlides($this->slides(), null, $secondAnchor, $signature);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $this->insert($document, [$first, $second]);
        $html = $document->saveHtml();

        self::assertStringNotContainsString('broken', $html);
        self::assertSame(2, substr_count($html, 'reader-slideshow'));

        $firstAnchorPosition = strpos($html, $firstAnchor);
        $secondAnchorPosition = strpos($html, $secondAnchor);
        $firstFigurePosition = strpos($html, 'reader-slideshow');
        self::assertNotFalse($firstFigurePosition);
        $secondFigurePosition = strpos($html, 'reader-slideshow', $firstFigurePosition + 1);
        self::assertNotFalse($secondFigurePosition);

        self::assertGreaterThan($firstAnchorPosition, $firstFigurePosition);
        self::assertLessThan($secondAnchorPosition, $firstFigurePosition);
        self::assertGreaterThan($secondAnchorPosition, $secondFigurePosition);
    }
}
