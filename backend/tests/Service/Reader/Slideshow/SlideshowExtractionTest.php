<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;

final class SlideshowExtractionTest extends TestCase
{
    public function testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $slideshows = $this->scanner()->scan($rawDocument);

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

    public function testSwiperTeaserCaptionsAndLinksSurviveTheSanitizer(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/swiper-teaser-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $body = '<p>An intro paragraph long enough to anchor the gallery that follows it here.</p>';
        $clean = $this->cleaner()->clean(
            $body,
            ['Article title', 'Article title'],
            new LeadImageCandidate(null, PageImageInventory::fromDocument(null)),
            ArticleMedia::none(),
            null,
            null,
            $this->scanner()->scan($rawDocument),
        );

        $safe = (new EntrySanitizer())->sanitize($clean);
        self::assertIsString($safe);
        self::assertStringContainsString('First headline', $safe);
        self::assertStringContainsString('https://www.example.com/first-article', $safe);
        self::assertStringContainsString('<p>Second headline</p>', $safe);
        // The script text inside the slide is not visible, so it never reaches the caption.
        self::assertStringNotContainsString('drop me', $safe);
    }

    public function testRestoresTheExcerptWhenTheGalleryLeavesTheBodyWithoutProse(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        // Readability dropped the header block, so its output carries no prose.
        $clean = $this->cleaner()->clean(
            '<div></div>',
            ['Article title', 'Article title'],
            new LeadImageCandidate(null, PageImageInventory::fromDocument(null)),
            ArticleMedia::none(),
            null,
            null,
            $this->scanner()->scan($rawDocument),
            [],
            'Er war passionierter Segler und bei den Norwegern ausgesprochen beliebt.',
        );

        self::assertStringContainsString('<p>Er war passionierter Segler', $clean);
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'passionierter Segler'));
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
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser(),
            new AuthorBioSeparator(),
        );
    }
}
