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
use App\Service\Reader\LeadImageSelector;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\ShareWidgetRemover;
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
            new FetchedPageNormalizer(new LazyImageSources(), new ShareWidgetRemover()),
            new LeadingTitleRemover(),
            new EntrySanitizer(),
            new LeadImageSelector(),
            new EdgeBoilerplateTrimmer(),
        );
    }

    private function noProxyResolver(): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return $resolver;
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
        // This page declares no og:image, so there is no hero to lead with.
        self::assertNull($result->image);
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

    public function testEmitsLeadImageWhenBodyHasNoImage(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-lead-image.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('<img', (string) $result->contentHtml);
        // readability finds the og:image even though it is outside the body; the
        // reader renders it as a hero so the article is not imageless.
        self::assertSame('https://site.test/hero.jpg', $result->image);
    }

    public function testEmitsLeadImageWhenTheBodyShowsADifferentPicture(): void
    {
        // #505: the og:image hero sits in the page header (a different CDN image
        // id than the body photo). The reader used to drop it because the body
        // held *some* image; it must now show the hero because it is not that one.
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-distinct-hero.html');
        $extractor = $this->extractor([new MockResponse($html, ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('4943526', (string) $result->contentHtml);
        self::assertStringNotContainsString('4943510', (string) $result->contentHtml);
        self::assertSame('https://site.test/4943510.jpg?imageId=4943510', $result->image);
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
            new FetchedPageNormalizer(new LazyImageSources(), new ShareWidgetRemover()),
            new LeadingTitleRemover(),
            new EntrySanitizer(),
            new LeadImageSelector(),
            new EdgeBoilerplateTrimmer(),
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
}
