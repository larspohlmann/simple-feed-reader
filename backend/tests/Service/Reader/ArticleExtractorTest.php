<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\EntrySanitizer;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\HtmlPageFetcher;
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

        $fetcher = new HtmlPageFetcher(new MockHttpClient($responses), new UrlGuard($resolver, new IpValidator()));

        return new ArticleExtractor($fetcher, new EntrySanitizer());
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

    public function testStripsDangerousMarkup(): void
    {
        $body = '<html lang="en"><body><article><h1>Hi</h1>'
            . str_repeat('<p>Real readable body content that scores well enough. </p>', 5)
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
        $fetcher = new HtmlPageFetcher(new MockHttpClient(), new UrlGuard($resolver, new IpValidator()));
        $extractor = new ArticleExtractor($fetcher, new EntrySanitizer());

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
}
