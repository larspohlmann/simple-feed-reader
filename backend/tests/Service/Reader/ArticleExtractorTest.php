<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\LazyImageSources;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\AttributeMediaSource;
use App\Service\Reader\Media\Source\JsonLdMediaSource;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\ShareIntentLinkRemover;
use App\Service\Reader\ShareWidgetRemover;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArticleExtractorTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function extractor(
        callable|iterable $responses,
        array $dnsMap = ['site.test' => ['93.184.216.34']],
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

        $fetcher = new HtmlPageFetcher(
            new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
            'TestAgent/1.0',
        );

        return new ArticleExtractor(
            $fetcher,
            new FetchedPageNormalizer(
                new LazyImageSources(),
                new ShareWidgetRemover(),
                new ShareIntentLinkRemover(),
                new SubstackGatedVideoPlaceholder(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(),
            $this->mediaScanner(),
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
            new EdgeBoilerplateTrimmer(),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter(new EmbedProviders([new YouTubeEmbedProvider()]), $markup),
            new SubstackPosterLink(),
            new PageMediaInserter($markup),
        );
    }

    private function mediaScanner(): PageMediaScanner
    {
        $urlKind = new MediaUrlKind(new DurableMediaUrl(), new EmbedProviders([]));

        return new PageMediaScanner([
            new JsonLdMediaSource($urlKind, new EmbedProviders([])),
            new AttributeMediaSource($urlKind, new MediaRelevance()),
        ]);
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
        $fetcher = new HtmlPageFetcher(
            new FailoverRequestSender(new MockHttpClient(), $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
            'TestAgent/1.0',
        );
        $extractor = new ArticleExtractor(
            $fetcher,
            new FetchedPageNormalizer(
                new LazyImageSources(),
                new ShareWidgetRemover(),
                new ShareIntentLinkRemover(),
                new SubstackGatedVideoPlaceholder(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(),
            $this->mediaScanner(),
        );

        $result = $extractor->extract('http://169.254.169.254/');

        self::assertFalse($result->ok);
        self::assertSame('fetch', $result->reason);
    }

    public function testUnextractablePageMapsToReason(): void
    {
        $extractor = $this->extractor([new MockResponse('<html lang="en"><body></body></html>', ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/x');

        self::assertFalse($result->ok);
        self::assertContains($result->reason, ['unextractable', 'empty']);
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
}
