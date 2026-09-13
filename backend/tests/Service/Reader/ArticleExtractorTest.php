<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Html\PictureSources;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\CustomElementUnwrapper;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\ImageButtonUnwrapper;
use App\Service\Reader\ImageWrapperClassRemover;
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\LazyImageSources;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaLanding;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\Provider\BrightcoveEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Sibling\SiblingIdRule;
use App\Service\Reader\Media\Sibling\SiblingMediaExtender;
use App\Service\Reader\Media\Source\AttributeMediaSource;
use App\Service\Reader\Media\Source\JsonLdMediaSource;
use App\Service\Reader\Media\Source\PageEmbedSource;
use App\Service\Reader\Media\Source\SemanticMediaSource;
use App\Service\Reader\Media\Source\YouTubeIdAttributeSource;
use App\Service\Reader\Media\StreamLocationResolver;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\MetaRefreshTarget;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\NoscriptImageUnwrapper;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\RelatedTeaserGridRemover;
use App\Service\Reader\ShareIntentLinkRemover;
use App\Service\Reader\ShareWidgetRemover;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArticleExtractorTest extends TestCase
{
    private const string WINDOWS_1252_SENTENCE = 'Café crème für señor Müller — “quoted” ½ ©.';

    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function extractor(
        callable|iterable $responses,
        array $dnsMap = ['site.test' => ['93.184.216.34']],
        ?SlideshowScanner $slideshowScanner = null,
    ): ArticleExtractor {
        $resolver = new class ($dnsMap) implements DnsResolverInterface {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->map[$hostname] ?? [];
            }
        };

        $redirects = new RedirectFollower(
            new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
        );
        $landing = new MediaLanding($redirects, 'TestAgent/1.0');

        return new ArticleExtractor(
            new HtmlPageFetcher($redirects, new MetaRefreshTarget(), new LandingChallenge(), 'TestAgent/1.0'),
            new FetchedPageNormalizer(
                new CustomElementUnwrapper(),
                new ImageButtonUnwrapper(),
                new NoscriptImageUnwrapper(),
                new LazyImageSources(new PictureSources()),
                new ShareWidgetRemover(),
                new ShareIntentLinkRemover(),
                new SubstackGatedVideoPlaceholder(),
                new ImageWrapperClassRemover(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(),
            $this->mediaScanner(),
            new BodyMediaResolver(
                new StreamLocationResolver($landing, $this->urlKind()),
                new SiblingMediaExtender(new SiblingIdRule(), $landing, $this->urlKind()),
            ),
            $slideshowScanner ?? new SlideshowScanner([]),
            new TeaserPlayerScanner($this->urlKind()),
            new RelatedTeaserGridRemover(),
        );
    }

    private function noProxyResolver(): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return $resolver;
    }

    private function bodyCleaner(): ReaderBodyCleaner
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

    private function mediaScanner(): PageMediaScanner
    {
        $urlKind = $this->urlKind();
        $providers = $this->providers();

        return new PageMediaScanner([
            new JsonLdMediaSource($urlKind, $providers),
            new PageEmbedSource($providers),
            new AttributeMediaSource($urlKind, new MediaRelevance()),
            new YouTubeIdAttributeSource($providers),
            new SemanticMediaSource($urlKind),
        ]);
    }

    private function urlKind(): MediaUrlKind
    {
        return new MediaUrlKind(new DurableMediaUrl(), $this->providers());
    }

    private function providers(): EmbedProviders
    {
        return new EmbedProviders([new YouTubeEmbedProvider(), new BrightcoveEmbedProvider()]);
    }

    public function testExtractsAndAbsolutisesImages(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('Real Headline', (string) $result->title);
        self::assertStringContainsString('substantial paragraph', (string) $result->contentHtml);
        self::assertStringContainsString('https://site.test/img/photo.jpg', (string) $result->contentHtml);
        self::assertStringNotContainsString('About', (string) $result->contentHtml);
        self::assertFalse($result->paywalled);
    }

    public function testDropsTheRelatedTeaserGridButKeepsTheStoryAndLeadImage(): void
    {
        // NDR (#1002): the "Mehr zum Thema" box is a grid of headline-linked
        // thumbnails. Readability keeps its images but strips the links, leaving
        // orphan thumbnails at the tail; the grid must go, the lead image stays.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/related-teaser-grid.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');
        $contentHtml = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertStringContainsString('sieben Jahren Haft', $contentHtml);
        self::assertStringContainsString('prozess-992', $contentHtml);
        self::assertStringNotContainsString('prozessdrogenurteil-100', $contentHtml);
        self::assertStringNotContainsString('kokain510', $contentHtml);
        self::assertStringNotContainsString('kokainurteil-100', $contentHtml);
    }

    public function testStampsFeedDeclaredDimensionsOnAMatchingBodyImage(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);
        $entry = new Entry(
            new Feed('https://site.test/feed.xml'),
            'g1',
            'https://site.test/post',
            'Post',
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
            new \DateTimeImmutable('2026-09-07T10:00:00Z'),
        );
        $entry->setMedia([new EntryMedium('https://site.test/img/photo.jpg', 'image', 1600, 900)], []);

        $result = $extractor->extract('https://site.test/post', feedMedia: FeedMedia::fromEntry($entry));

        self::assertStringContainsString('width="1600"', (string) $result->contentHtml);
        self::assertStringContainsString('height="900"', (string) $result->contentHtml);
    }

    public function testKeepsOneMarkedNarrationPlayerAndDropsTheDeadOne(): void
    {
        // ZEIT (#903): the page's own <audio> names its file only in data-src,
        // which the sanitizer strips, so it must not survive as a dead control.
        // The one player that reaches the reader is the recovered, marked one.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-narration-zeit.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');
        $contentHtml = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertSame(1, substr_count($contentHtml, '<audio'));
        self::assertStringContainsString('class="reader-narration"', $contentHtml);
        self::assertStringNotContainsString('Ihr Browser unterstützt', $contentHtml);
    }

    public function testKeepsTheHeroAboveATopPlacedNarrationPlayer(): void
    {
        // #907: a narration audio player is top-placed, but it is not a picture,
        // so the page hero must still be restored — above the compact player.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-narration-with-hero-zeit.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');
        $contentHtml = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertStringContainsString('img.zeit.de/zeit-magazin/2026/38/hundert-jahre-zukunft', $contentHtml);
        self::assertSame(1, substr_count($contentHtml, '<audio'));
        self::assertStringContainsString('class="reader-narration"', $contentHtml);
        self::assertLessThan(strpos($contentHtml, '<audio'), strpos($contentHtml, '<img'));
    }

    public function testDropsASilentTextToSpeechWidgetButKeepsTheArticle(): void
    {
        // CBC (#959): a script-driven text-to-speech widget with no <audio>.
        // The reader has no player to attach, so the whole widget is chrome;
        // the lead figure and prose beside it stay.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-tts-widget-cbc.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');
        $contentHtml = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('texttospeech.svg', $contentHtml);
        self::assertStringNotContainsString('Listen to this article', $contentHtml);
        self::assertStringNotContainsString('AI-based technology', $contentHtml);
        self::assertStringContainsString('third attendee of the Burning Man festival', $contentHtml);
    }

    public function testRebuildsAPpMediaGalleryAsAReaderSlideshow(): void
    {
        // A "purple/pp-media" carousel (Mopo et al.): normalisation keeps the
        // slideshowcontainer/slideshow-image classes because each slide holds
        // caption text, so the recogniser sees it on the raw page and the cleaner
        // rebuilds it even though readability would drop the original box.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-slideshow-ppmedia.html');
        $extractor = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            slideshowScanner: new SlideshowScanner(
                [new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver())],
            ),
        );

        $result = $extractor->extract('https://site.test/post');
        $content = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertStringContainsString('reader-slideshow', $content);
        self::assertStringContainsString('Ein roter Lieferwagen passiert die Baustelle.', $content);
        // The publisher's original carousel is removed, so each photo appears once
        // (normalisation promotes the <source> rendition over the small <img>).
        self::assertStringNotContainsString('pp-media--gallery', $content);
        self::assertSame(1, substr_count($content, 'site.test/a-large.webp'));
        self::assertSame(1, substr_count($content, 'site.test/b.jpg'));
    }

    public function testRestoresLazyLoadedImagesInsteadOfLeavingEmptyFrames(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-lazy-images.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        // The lazy source is promoted, absolutised, and survives the sanitizer —
        // the blank placeholder never reaches the client.
        self::assertStringContainsString(
            '<img src="https://site.test/img/photo.jpg"',
            (string) $result->contentHtml,
        );
        self::assertStringNotContainsString('data:image', (string) $result->contentHtml);
    }

    public function testRecoversAnImageStoredOnlyInsideNoscript(): void
    {
        // heise ships the real photo only inside <noscript>, next to a `data:`
        // placeholder <img>; the sanitizer would otherwise drop the tag with
        // the image still inside it (#894).
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-noscript-image.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString(
            '<img src="https://site.test/img/photo.jpg"',
            (string) $result->contentHtml,
        );
        self::assertStringNotContainsString('data:image', (string) $result->contentHtml);
        self::assertStringNotContainsString('<noscript', (string) $result->contentHtml);
    }

    public function testRestoresTheLeadIntoATextOnlyBody(): void
    {
        // readability drops the og:image (it sits outside the scored body) and the
        // body carries no picture of its own. With nothing to duplicate, the lead
        // is restored at the top so the story is not left imageless (#681).
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-lead-image.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('<img src="https://site.test/hero.jpg"', (string) $result->contentHtml);
    }

    public function testRestoresTheLeadInlineWithItsCaption(): void
    {
        // heise: the dropped header figure carries a figcaption naming the
        // photographer. The restored lead must bring that caption along, not
        // just the bare image (#894).
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-lead-image-caption.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');
        $content = (string) $result->contentHtml;

        self::assertTrue($result->ok);
        self::assertStringContainsString('<img src="https://site.test/hero.jpg"', $content);
        self::assertStringContainsString(
            '<figcaption>Ugreen Home Agent auf der IFA 2026 (Bild: Berti Kolbow-Lehradt / heise medien)</figcaption>',
            $content,
        );
    }

    public function testRestoresADistinctPageHeroAboveTheBodyPhoto(): void
    {
        // #681: the og:image hero sits in the page header (a different CDN image id
        // than the body photo). readability drops it as chrome; because the page
        // draws it and the body's own photo is a different picture, the lead is
        // restored at the top — the mopo pattern that used to lose the first image.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-distinct-hero.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('4943526', (string) $result->contentHtml);
        self::assertStringContainsString('4943510', (string) $result->contentHtml);
        self::assertLessThan(
            strpos((string) $result->contentHtml, '4943526'),
            strpos((string) $result->contentHtml, '4943510'),
            'the restored hero must lead the body photo',
        );
    }

    public function testStripsDangerousMarkup(): void
    {
        $body = '<html lang="en"><body><article><h1>Hi</h1>'
            . str_repeat('<p>Real readable body content that scores well past the character threshold. </p>', 12)
            . '<script>alert(1)</script></article></body></html>';
        $extractor = $this->extractor([new MockResponse($body, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('<script', (string) $result->contentHtml);
    }

    public function testFetchFailureMapsToFetchReason(): void
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return [];
            }
        };
        $redirects = new RedirectFollower(
            new FailoverRequestSender(new MockHttpClient(), $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
        );
        $landing = new MediaLanding($redirects, 'TestAgent/1.0');
        $extractor = new ArticleExtractor(
            new HtmlPageFetcher($redirects, new MetaRefreshTarget(), new LandingChallenge(), 'TestAgent/1.0'),
            new FetchedPageNormalizer(
                new CustomElementUnwrapper(),
                new ImageButtonUnwrapper(),
                new NoscriptImageUnwrapper(),
                new LazyImageSources(new PictureSources()),
                new ShareWidgetRemover(),
                new ShareIntentLinkRemover(),
                new SubstackGatedVideoPlaceholder(),
                new ImageWrapperClassRemover(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(),
            $this->mediaScanner(),
            new BodyMediaResolver(
                new StreamLocationResolver($landing, $this->urlKind()),
                new SiblingMediaExtender(new SiblingIdRule(), $landing, $this->urlKind()),
            ),
            new SlideshowScanner([]),
            new TeaserPlayerScanner($this->urlKind()),
            new RelatedTeaserGridRemover(),
        );

        $result = $extractor->extract('http://169.254.169.254/');

        self::assertFalse($result->ok);
        self::assertSame('fetch', $result->reason);
    }

    public function testFetchFailureCarriesTheRealErrorMessageAsDetail(): void
    {
        $body = '<html><body><h1>Access Denied</h1><p>Your request was blocked.</p></body></html>';
        $extractor = $this->extractor([new MockResponse($body, ['http_code' => 403])]);

        $result = $extractor->extract('https://site.test/x');

        self::assertFalse($result->ok);
        self::assertSame('fetch', $result->reason);
        self::assertSame('HTTP 403 Forbidden — Access Denied Your request was blocked.', $result->detail);
    }

    public function testUnextractablePageMapsToReason(): void
    {
        $extractor = $this->extractor([new MockResponse('<html lang="en"><body></body></html>', ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/x');

        self::assertFalse($result->ok);
        self::assertContains($result->reason, ['unextractable', 'empty']);
    }

    public function testStripsASemanticHeaderMasthead(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-masthead-header.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'The Haiku Challenge', 'Clark Strand');
        $content = (string) $result->contentHtml;

        self::assertStringNotContainsString('badge.png', $content);
        self::assertStringNotContainsString('Culture', $content);
        self::assertStringNotContainsString('Clark Strand', $content);
        self::assertStringNotContainsString('Sep 01, 2026', $content);
        self::assertStringNotContainsString('The Haiku Challenge', $content);
        self::assertStringContainsString('Announcing the winning poems', $content);
        self::assertStringContainsString('Illustration by Jing Li', $content);
        self::assertStringContainsString('Because cumulus clouds', $content);
    }

    public function testStripsABreadcrumbSeparatorAndKickerMasthead(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-masthead-breadcrumb.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'Political Balancing Act');
        $content = (string) $result->contentHtml;

        self::assertStringNotContainsString('2026/36', $content);
        self::assertStringNotContainsString('Ausland', $content);
        self::assertStringNotContainsString('Demokratie', $content);
        self::assertStringNotContainsString('Kapitalismus', $content);
        self::assertStringNotContainsString('Political Balancing Act', $content);
        self::assertStringContainsString('Arab-Israeli party', $content);
    }

    public function testStripsAMetaToolbarThatSitsBelowAStandfirst(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-masthead-toolbar.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'Shoulder to Shoulder');
        $content = (string) $result->contentHtml;

        self::assertStringContainsString('reaffirmed its course', $content);
        self::assertStringNotContainsString('7. September 2026', $content);
        self::assertStringNotContainsString('9 min.', $content);
        self::assertStringNotContainsString('Drucken', $content);
        self::assertStringNotContainsString('Korrektur', $content);
        self::assertStringNotContainsString('mehr_artikel_icon', $content);
        self::assertStringNotContainsString('icons/expand', $content);
        self::assertStringContainsString('campaign_posters_w.webp', $content);
        self::assertStringContainsString('Photo: zVg', $content);
        self::assertStringContainsString('pro-authoritarian gathering', $content);
    }

    public function testStripsAReadingTimeAndCategoryMetaBar(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-masthead-metabar.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'September Wallpapers');
        $content = (string) $result->contentHtml;

        self::assertStringNotContainsString('11 min read', $content);
        self::assertStringNotContainsString('Wallpapers</a>', $content);
        self::assertStringContainsString('welcome the new month', $content);
        self::assertStringContainsString('fifteen years', $content);
    }

    public function testKeepsHeadingsAndImagesOnBlockComponentPages(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-block-components.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'Block Component Headline');

        self::assertTrue($result->ok);
        // Subheadings and the figure survive the wrapper-chain layout.
        self::assertStringContainsString('First Section', (string) $result->contentHtml);
        self::assertStringContainsString('Second Section', (string) $result->contentHtml);
        self::assertStringContainsString('<img', (string) $result->contentHtml);
        // The body headline duplicates the entry title, so it is dropped …
        self::assertStringNotContainsString('Block Component Headline', (string) $result->contentHtml);
        // … and screen-reader-only labels never reach the client.
        self::assertStringNotContainsString('Image source,', (string) $result->contentHtml);
        self::assertStringContainsString('A caption line', (string) $result->contentHtml);
    }

    public function testKeepsTheArticleWhenCollapsingWouldElevatePublisherChrome(): void
    {
        // A real Shopify blog capture (#476, entry 466491). Readability extracts
        // the article on the neutral candidate, but the wrapper-chain collapse
        // flips the winner to the promo banner. Dual extraction keeps the richer
        // (article) result.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-shopify-promo.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('Hand aufs Herz', (string) $result->contentHtml);
        self::assertStringNotContainsString('DU MAGST DEN ANKERHERZ BLOG', (string) $result->contentHtml);
    }

    public function testExtractsAPageThatNeedsNoWrapperCollapse(): void
    {
        // article.html has no single-child <div> chain, so the collapsed
        // candidate equals the conservative one and only one parse runs.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('First substantial paragraph', (string) $result->contentHtml);
    }

    public function testStripsTheShariffBarFromTheExtractedArticle(): void
    {
        // #582: the Shariff share bar leads the hanfjournal body ("teilen …
        // merken"). It must not appear in the extracted, reader-ready HTML,
        // while the real article text survives.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/hanfjournal-shariff.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/cannafair');

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('teilen', (string) $result->contentHtml);
        self::assertStringNotContainsString('merken', (string) $result->contentHtml);
        self::assertStringContainsString('Cannafair', (string) $result->contentHtml);
    }

    public function testTrimsATrailingNewsletterPromptFromTheExtractedArticle(): void
    {
        // #582 stage 2: a newsletter prompt at the tail of the article is
        // removed, while the middle prose is kept. The content root needs at
        // least 4 top-level blocks — the edge cap is floor(0.25 * blockCount),
        // and a 3-block root (cap 0) would make the trim a no-op.
        //
        // A "related posts" grid is not usable here: readability's own
        // UNLIKELY_CANDIDATES/NEGATIVE regexes already match "related" and its
        // own cleanConditionally('div') removes any link-heavy div outright, so
        // such a fixture would pass without EdgeBoilerplateTrimmer ever running
        // (verified empirically). "newsletter" matches neither readability
        // regex and this block carries no links, so only the trimmer's
        // fingerprint + corroborating-heading-phrase rule removes it.
        $prose = str_repeat('Ein langer echter Absatz mit Fliesstext. ', 8);
        $body = '<article><div class="entry-content">'
            . '<p>' . $prose . '</p><p>' . $prose . '</p><p>' . $prose . '</p>'
            . '<div class="newsletter"><h3>Jetzt anmelden</h3>'
            . '<p>Melde dich für unseren Newsletter an, um nichts zu verpassen.</p></div>'
            . '</div></article>';
        $html = '<!doctype html><html lang="de"><head><title>T</title></head><body>' . $body . '</body></html>';
        $extractor = $this->extractor([new MockResponse($html, [
            'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
        ])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('Jetzt anmelden', (string) $result->contentHtml);
        self::assertStringNotContainsString('unseren Newsletter', (string) $result->contentHtml);
        self::assertStringContainsString('Fliesstext', (string) $result->contentHtml);
    }

    /**
     * On a public-radio page the audio IS the article: the prose extracts to a
     * duration line and a few teaser links, under the length gate. Media found
     * on the page is enough to call it an article.
     */
    public function testAMediaCandidateSatisfiesTheLengthGate(): void
    {
        $html = '<html><head><title>Bildung</title></head><body><article>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
            . '<p>85:29 Minuten. Ein kurzer Teasertext.</p>'
            . '</article></body></html>';
        $extractor = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.deutschlandfunkkultur.de' => ['93.184.216.34']],
        );

        $result = $extractor->extract('https://www.deutschlandfunkkultur.de/bildung-100.html');

        self::assertTrue($result->ok);
        self::assertStringContainsString('bildung.mp3', (string) $result->contentHtml);
    }

    /**
     * tagesschau 494183: readability drops the inline video block because its
     * only text is a link, so the body keeps no trace of where the player was.
     * The paragraph before it survives, and that is where the player belongs.
     */
    public function testRestoresAnInlineVideoAfterTheParagraphItFollowed(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-inline-video.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post', 'Inline video headline');

        $body = (string) $result->contentHtml;
        self::assertTrue($result->ok);
        self::assertSame(1, substr_count($body, '<video'));
        self::assertStringContainsString('TV-20260831-2220-5800.webxxl.h264.mp4', $body);
        self::assertGreaterThan(strpos($body, 'Der dritte Absatz'), strpos($body, '<video'), 'after its paragraph');
        self::assertLessThan(strpos($body, 'Der vierte Absatz'), strpos($body, '<video'), 'before the next one');
    }

    public function testFlagsAPaywalledArticleDeclaredInJsonLd(): void
    {
        $result = $this->extractFixture('article-paywalled-jsonld-boolean.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
        self::assertStringContainsString('First substantial paragraph', (string) $result->contentHtml);
    }

    public function testTrustsAPremiumDeclarationEvenWithoutAGatedBlock(): void
    {
        // The fixture declares the article premium (string "False") and serves the
        // full body with no gated block, as mopo.de does for its MOPO+ articles.
        // The declaration is trusted; the reader shows the banner (a #908 trade-off).
        $result = $this->extractFixture('article-paywalled-jsonld-string.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
    }

    public function testFlagsAPaywallBlockBelowTheExtractedPreview(): void
    {
        $result = $this->extractFixture('article-paywalled-dom-block.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
        self::assertStringContainsString('Second substantial paragraph', (string) $result->contentHtml);
    }

    public function testFlagsAMemberfulGatedArticleWithNoDeclarationOrGateClass(): void
    {
        // psychedelicalpha.com: the JSON-LD @graph declares no isAccessibleForFree
        // and the gate is a generic `<div class="join">`; the Memberful checkout
        // link below the free intro carries the verdict (#998).
        $result = $this->extractFixture('article-paywalled-memberful.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
        self::assertStringContainsString('Second substantial paragraph', (string) $result->contentHtml);
    }

    public function testTrustsThePremiumDeclarationOnAZeitFadedArticle(): void
    {
        // ZEIT+ fades the last visible paragraph and declares the article premium.
        // The declaration alone now carries the verdict; the fade class is no
        // longer read (#908, replacing the #898 fade gate).
        $result = $this->extractFixture('article-paywalled-faded-paragraph.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
    }

    public function testDoesNotFlagAFreePostWithoutAPaywallBlock(): void
    {
        $result = $this->extractFixture('article-free-substack.html');

        self::assertTrue($result->ok);
        self::assertFalse($result->paywalled);
    }

    public function testFlagsAPaywallBannerAboveAnUndeclaredArticle(): void
    {
        // Without the anchor test, a paywall-class banner above a free article now
        // flags. The fixture declares nothing, so the block presence decides (an
        // accepted #908 trade-off: simplicity over the position guard).
        $result = $this->extractFixture('article-free-paywall-banner.html');

        self::assertTrue($result->ok);
        self::assertTrue($result->paywalled);
    }

    public function testTheJsonLdDeclarationDecidesAloneOverAPaywallBlock(): void
    {
        $result = $this->extractFixture('article-free-jsonld-true.html');

        self::assertTrue($result->ok);
        self::assertFalse($result->paywalled);
    }

    public function testRecoversEveryEmbedThePageCarriesEachUnderItsOwnSection(): void
    {
        $result = $this->extractFixture('media/multi-embed-page.html');

        self::assertTrue($result->ok);
        $html = (string) $result->contentHtml;
        preg_match_all('#youtube-nocookie\.com/embed/(aaaaaaaaaa\d)#', $html, $players);
        self::assertSame(['aaaaaaaaaa1', 'aaaaaaaaaa2', 'aaaaaaaaaa3', 'aaaaaaaaaa4'], $players[1]);
        // Under its own section, not above the lead: the first player follows the first section's prose.
        self::assertGreaterThan((int) strpos($html, 'The first remix took'), (int) strpos($html, 'aaaaaaaaaa1'));
        self::assertLessThan((int) strpos($html, 'The second remix dragged'), (int) strpos($html, 'aaaaaaaaaa1'));
    }

    /** nature.com 495343: lazy pictures on data-srcset, one in a custom element, one in a media-classed wrapper, one in a captioned figure. */
    public function testKeepsEveryPhotoOfAnImmersiveGalleryBesideItsCaption(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-immersive-gallery.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/immersive/story/index.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        foreach (['eclipse/shadows-750x422.webp', 'comet/starlink-750x422.webp', 'rock/alloy-750x751.jpg'] as $photo) {
            self::assertStringContainsString('https://site.test/immersive/story/assets/' . $photo, $body);
        }
        self::assertStringNotContainsString('sh-background-transition', $body);
        self::assertLessThan((int) strpos($body, 'Eclipse shadows.'), (int) strpos($body, 'eclipse/shadows'));
        self::assertLessThan((int) strpos($body, 'The Starlink way.'), (int) strpos($body, 'comet/starlink'));
        self::assertGreaterThan((int) strpos($body, 'Hiroshimaite.'), (int) strpos($body, 'rock/alloy'));
        self::assertLessThan((int) strpos($body, 'The nuclear detonation'), (int) strpos($body, 'rock/alloy'));
    }

    /** Al Jazeera 469835: the Brightcove link takes the place of the thumbnail the body already shows. */
    public function testPlacesTheBrightcovePlayerWhereTheBodyShowedItsThumbnail(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/aljazeera-brightcove.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.aljazeera.com' => ['93.184.216.34']],
        )->extract('https://www.aljazeera.com/video/newsfeed/2026/8/20/harry-kane');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertMatchesRegularExpression(
            '#<a href="https://players\.brightcove\.net/665003303001/6tKQRAx7lu_default/index\.html'
            . '\?videoId(?:=|&\#61;)6403736850112"#',
            $body,
            'the Brightcove player is a link to the canonical videoId URL',
        );
        self::assertSame(
            1,
            substr_count($body, 'image-1787184739.jpg'),
            'the thumbnail is the poster inside the link, not a second picture',
        );
        self::assertLessThan(
            (int) strpos($body, 'English footballer Harry Kane won'),
            (int) strpos($body, 'players.brightcove.net'),
        );
    }

    /** Al Jazeera 495829: the node's file plays in place of its thumbnail; the Brightcove page is not a second player. */
    public function testOneVideoObjectWithFileAndPlayerPageYieldsOnePlayer(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/aljazeera-file-and-brightcove.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.aljazeera.com' => ['93.184.216.34']],
        )->extract('https://www.aljazeera.com/video/newsfeed/2026/9/2/video-chinese-president-xi');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertSame(1, substr_count($body, '<video'));
        self::assertStringContainsString('cb625c3e-c720-462f-8cc6-af9cad40a6c5/main.mp4', $body);
        self::assertStringNotContainsString('players.brightcove.net', $body);
    }

    /** ZDF 491430: the stream is a <video> at the Akamai master its playlist URL redirects to (#782 follow-up). */
    public function testEmitsAnHlsStreamAsAVideoAtItsLanding(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/zdf-hls-video.html');
        $master = 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/08/260831_istaf_moma/1/'
            . '260831_istaf_moma,_508k_p9,_808k_p11,_1628k_p13,_3328k_p15,_6628k_p61,v17.mp4.csmil/master.m3u8';
        $result = $this->extractor(
            [
                new MockResponse($html, ['http_code' => 200]),
                new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $master]]),
                new MockResponse('#EXTM3U', ['http_code' => 200]),
            ],
            ['www.zdfheute.de' => ['93.184.216.34'], 'zdfvod.akamaized.net' => ['93.184.216.35']],
        )->extract('https://www.zdfheute.de/video/zdf-morgenmagazin/istaf-berlin-em-stars-100.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertStringContainsString('src="' . $master . '"', $body);
        self::assertStringNotContainsString('zdfheute.de/api/video', $body);
        self::assertMatchesRegularExpression(
            '#<video[^>]*poster="https://www\.zdfheute\.de/assets/istaf-berlin-em-stars-102~1920x1080[^"]*"#',
            $body,
        );
        self::assertStringNotContainsString('ngp.zdf.de', $body);
    }

    /** Guardian 493958: the body opens with the player where the page had no URL at all, and no stacked lead. */
    public function testAYouTubeIdInADataAttributeBecomesTheLeadPlayer(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/guardian-youtube-atom.html');
        $url = 'https://www.theguardian.com/science/video/2026/sep/01'
            . '/could-humans-ever-communicate-with-whales-video';
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.theguardian.com' => ['93.184.216.34']],
        )->extract($url);

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertMatchesRegularExpression(
            '#^<a [^>]*href="https://www\.youtube-nocookie\.com/embed/pz8VRrI0p0U"#',
            $body,
        );
        self::assertStringContainsString('src="https://i.ytimg.com/vi/pz8VRrI0p0U/hqdefault.jpg"', $body);
        self::assertSame(1, substr_count($body, '<img'), 'the top-placed player is the lead visual; no hero above it');
        self::assertStringNotContainsString('U8duwJ2mKWs', $body);
        self::assertStringContainsString('earliest known recording of whale song', $body);
    }

    /** tagesschau 496523: both players reach the body; the video carries the still the page drew beside it. */
    public function testABroadcastPageWithoutOgImageKeepsItsVideoBesideTheAudio(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/ard-broadcast-no-og-image.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.tagesschau.de' => ['93.184.216.34']],
        )->extract('https://www.tagesschau.de/tagesschau_in_einfacher_sprache/tse-1410.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertMatchesRegularExpression(
            '#<video[^>]*src="https://tagesschau-progressive\.ard-mcdn\.de/video/2026/0902/'
                . 'TV-20260902-1804-0100\.[a-z]+\.h264\.mp4"#',
            $body,
        );
        self::assertMatchesRegularExpression(
            '#<video[^>]*poster="https://images\.tagesschau\.de/[^"]*sendungsbild-1789662[^"]*"#',
            $body,
        );
        $audioTag = '<audio controls preload="none" '
            . 'src="https://tagesschau-podcast.ard-mcdn.de/audio/2026/0902/TV-20260902-1804-0100.mp3"';
        self::assertStringContainsString($audioTag, $body);
        self::assertStringNotContainsString('sendungsbild-other', $body);
    }

    /** zdfheute 1374175: three of four videos exist only as ids in the payload; the page's own template recovers them. */
    public function testRecoversTheVideosThePageNamesOnlyByASiblingId(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/zdf-sibling-video-configs.html');
        $landing = static fn (string $name): string => 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/09/'
            . $name . '/1/' . $name . ',_508k_p9,_6628k_p61,v17.mp4.csmil/master.m3u8';
        $pair = static fn (string $name): array => [
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $landing($name)]]),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ];
        $result = $this->extractor(
            [
                new MockResponse($html, ['http_code' => 200]),
                ...$pair('260902_russland_taktik_viu'),
                ...$pair('260902_leipzig_verdaechtige_interview_hli'),
                ...$pair('260902_clip_12_mom'),
                ...$pair('260825_hju_sgs_lange'),
            ],
            ['www.zdfheute.de' => ['93.184.216.34'], 'zdfvod.akamaized.net' => ['93.184.216.35']],
        )->extract('https://www.zdfheute.de/politik/deutschland/leipzig-drohne-sabotage-100.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertSame(4, substr_count($body, '<video'), 'the declared stream and its three siblings');
        $names = [
            '260902_russland_taktik_viu',
            '260902_leipzig_verdaechtige_interview_hli',
            '260902_clip_12_mom',
            '260825_hju_sgs_lange',
        ];
        foreach ($names as $name) {
            self::assertStringContainsString('src="' . $landing($name) . '"', $body);
        }
        self::assertSame(0, substr_count($body, '<img'), 'each player replaced its figure image; no picture was added');
        self::assertStringNotContainsString('zdfheute-politik-100', $body);
        self::assertLessThan(
            (int) strpos($body, 'Zwei Verdächtige sollen'),
            (int) strpos($body, '260902_russland_taktik_viu'),
            'the first player sits where its figure stood, before the second paragraph',
        );
    }

    public function testRendersASubstackAudioPostAsItsProseAndPlayer(): void
    {
        // #786: a post without pictures reports the subscribe card as og:image,
        // keeps its player's clock readouts as paragraphs, links to itself with
        // action=share, and plays through a bare script-driven <audio>.
        $body = $this->extractFixture('substack-audio-post.html')->contentHtml ?? '';

        self::assertStringNotContainsString('<img', $body, 'the subscribe card is not a lead');
        self::assertStringNotContainsString('0:00', $body);
        self::assertStringNotContainsString('13:34', $body);
        self::assertStringNotContainsString('Share', $body);
        self::assertMatchesRegularExpression('#<audio [^>]*controls[^>]*>#', $body);
        self::assertStringContainsString(
            'src="https://site.test/api/v1/audio/upload/7adcfe96-6a96-49c6-a26e-0192656e3c1a/src"',
            $body,
        );
        self::assertStringContainsString('rubber band collection', $body);
        self::assertStringContainsString('Thank you for subscribing', $body);
    }

    private function extractFixture(string $fixture): ExtractionResult
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/' . $fixture);

        return $this->extractor([new MockResponse($html, ['http_code' => 200])])->extract('https://site.test/post');
    }

    public function testDecodesAPageWhoseCharsetOnlyTheHttpHeaderDeclares(): void
    {
        // #904: a legacy site that states its charset in Content-Type alone.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-windows-1252.html');
        $extractor = $this->extractor([new MockResponse($html, [
            'http_code' => 200,
            'response_headers' => ['content-type' => ['text/html; charset=windows-1252']],
        ])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString(self::WINDOWS_1252_SENTENCE, (string) $result->contentHtml);
        self::assertStringContainsString('The Real Headline — Site', (string) $result->title);
    }

    public function testDecodesAPageWhoseMetaDeclaresItsCharset(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-windows-1252-meta.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString(self::WINDOWS_1252_SENTENCE, (string) $result->contentHtml);
    }
}
