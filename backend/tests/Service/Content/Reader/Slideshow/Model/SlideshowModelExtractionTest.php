<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow\Model;

use App\Service\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Model\FeedMediaModel;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowRecognizer\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowRecognizer\TagesschauCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Service\Reader\ReaderBodyCleanerTest;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class SlideshowModelExtractionTest extends TestCase
{
    use ParsesHtml;

    public function testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = $this->document($raw);

        $slideshows = $this->scanner()->scan($rawDocument);

        // The cleaned "body" here is the readability output: the heading survives,
        // the attribute-only carousel div is gone.
        $body = '<h2>Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</h2><p>Body text.</p>';
        $clean = $this->cleaner()->clean($body, BodyCleaningInputs::withSlideshows($slideshows));

        self::assertStringContainsString('reader-slideshow', $clean);
        self::assertSame(3, substr_count($clean, '<img'));
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'Hauptgründe'));
    }

    public function testSwiperTeaserCaptionsAndLinksSurviveTheSanitizer(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../Fixtures/Slideshow/swiper-teaser-carousel.html');
        self::assertIsString($raw);
        $rawDocument = $this->document($raw);

        $body = '<p>An intro paragraph long enough to anchor the gallery that follows it here.</p>';
        $clean = $this->cleaner()->clean(
            $body,
            BodyCleaningInputs::withSlideshows($this->scanner()->scan($rawDocument)),
        );

        $safe = (new EntrySanitizer(new TrailingBlankRemover()))->sanitize($clean);
        self::assertIsString($safe);
        self::assertStringContainsString('First headline', $safe);
        self::assertStringContainsString('https://www.example.com/first-article', $safe);
        self::assertStringContainsString('<p>Second headline</p>', $safe);
        // The script text inside the slide is not visible, so it never reaches the caption.
        self::assertStringNotContainsString('drop me', $safe);
    }

    public function testRestoresTheExcerptWhenTheGalleryLeavesTheBodyWithoutProse(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = $this->document($raw);

        // Readability dropped the header block, so its output carries no prose.
        $clean = $this->cleaner()->clean('<div></div>', new BodyCleaningInputModel(
            ['Article title', 'Article title'],
            BodyCleaningInputs::noLeadImage(),
            ArticleMediaModel::none(),
            FeedMediaModel::none(),
            slideshows: $this->scanner()->scan($rawDocument),
            excerpt: 'Er war passionierter Segler und bei den Norwegern ausgesprochen beliebt.',
        ));

        self::assertStringContainsString('<p>Er war passionierter Segler', $clean);
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'passionierter Segler'));
    }

    public function testDropsTheTeaserCarouselsOfIdenticalPlaceholderImages(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../Fixtures/Slideshow/toi-placeholder-carousel.html');
        self::assertIsString($raw);
        $rawDocument = $this->document($raw);

        self::assertSame([], $this->scanner()->scan($rawDocument));
    }

    public function testRecoversRealImagesBehindARepeatedRemotePlaceholderSrc(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../Fixtures/Slideshow/lazy-placeholder-gallery.html');
        self::assertIsString($raw);
        $rawDocument = $this->document($raw);

        $slideshows = $this->scanner()->scan($rawDocument);

        self::assertCount(1, $slideshows);
        self::assertSame(
            [
                'https://cdn.example.com/photo/first-1600.jpg',
                'https://cdn.example.com/photo/second-1600.jpg',
                'https://cdn.example.com/photo/third-1600.jpg',
            ],
            array_map(static fn ($slide): string => $slide->imageUrl, $slideshows[0]->slides),
        );
    }

    private function scanner(): SlideshowScanner
    {
        return new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
    }

    private function cleaner(): ReaderBodyCleaner
    {
        $embedProviders = new EmbedProviders([new YouTubeEmbedProvider()]);

        return new ReaderBodyCleaner(ReaderBodyCleanerTest::steps($embedProviders));
    }
}
