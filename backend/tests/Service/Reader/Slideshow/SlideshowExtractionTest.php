<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use PHPUnit\Framework\TestCase;

final class SlideshowExtractionTest extends TestCase
{
    public function testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $scanner = new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
        $slideshows = $scanner->scan($rawDocument);

        // The cleaned "body" here is the readability output: the heading survives,
        // the attribute-only carousel div is gone.
        $body = '<h2>Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</h2><p>Body text.</p>';
        $clean = $this->cleaner()->clean(
            $body,
            ['Article title', 'Article title'],
            new LeadImageCandidate(null, PageImageInventory::fromDocument(null)),
            ArticleMedia::none(),
            null,
            null,
            $slideshows,
        );

        self::assertStringContainsString('reader-slideshow', $clean);
        self::assertSame(3, substr_count($clean, '<img'));
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'Hauptgründe'));
    }

    private function cleaner(): ReaderBodyCleaner
    {
        $markup = new MediaMarkup();

        return new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter(new EmbedProviders([new YouTubeEmbedProvider()]), $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
        );
    }
}
