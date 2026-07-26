<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogFaviconFetcherTest extends TestCase
{
    private const string ICON_URL = 'https://www.theverge.com/favicon.ico';

    /**
     * UrlGuard is `final` and, per the pattern already established in
     * HttpFeedFetcherTest/HtmlPageFetcherTest, is exercised for real here
     * rather than mocked: a fake DnsResolverInterface stands in for the
     * network, and the real IpValidator makes the SSRF check genuine.
     *
     * @param array<string, list<string>> $dnsMap
     */
    private function fetcher(
        MockHttpClient $client,
        array $dnsMap = ['www.theverge.com' => ['93.184.216.34']],
    ): CatalogFaviconFetcher {
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

        return new CatalogFaviconFetcher($client, new UrlGuard($resolver, new IpValidator()));
    }

    public function testReturnsTheBytesAndContentTypeOfAnImageResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY', [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $icon = $this->fetcher($client)->download(self::ICON_URL);

        self::assertSame('BINARY', $icon->bytes);
        self::assertSame('image/png', $icon->contentType);
        self::assertSame(self::ICON_URL, $icon->sourceUrl);
    }

    public function testRejectsANonImageContentType(): void
    {
        $client = new MockHttpClient(new MockResponse('not an image', [
            'response_headers' => ['content-type' => ['text/html']],
        ]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testRejectsAnOversizedResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            str_repeat('x', CatalogFaviconFetcher::MAX_BYTES + 1),
            ['response_headers' => ['content-type' => ['image/png']]],
        ));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testRejectsANonSuccessStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testPropagatesTheSsrfGuardAsAnUnavailableIcon(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY'));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client, ['www.theverge.com' => ['127.0.0.1']])->download(self::ICON_URL);
    }
}
