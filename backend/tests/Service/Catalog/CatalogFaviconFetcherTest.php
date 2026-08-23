<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\CatalogFaviconFetcher;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
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

        return new CatalogFaviconFetcher(
            new FailoverRequestSender($client, $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
        );
    }

    private function noProxyResolver(): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return $resolver;
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

    public function testFailsOverToIpv4WhenIpv6ConnectsButResetsBeforeHeaders(): void
    {
        // The both-families pin leads with IPv6; a route that resets at the TLS
        // handshake (heise's IPv6 from Strato) is unrecoverable by the client, so
        // the icon must still download over IPv4.
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $pinnedAddresses = $resolve['www.theverge.com'];

            return str_contains($pinnedAddresses, ':')
                ? new MockResponse('', ['error' => 'Connection reset by peer'])
                : new MockResponse('ICON', ['response_headers' => ['content-type' => ['image/png']]]);
        });

        $icon = $this
            ->fetcher($client, ['www.theverge.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']])
            ->download(self::ICON_URL);

        self::assertSame('ICON', $icon->bytes);
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

    public function testRejectsAnEmptyBody(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testRejectsARedirectToAPrivateAddress(): void
    {
        // 169.254.169.254 is an IP literal, so the real UrlGuard + IpValidator
        // reject it on the redirect hop without needing a DNS entry for it.
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'http://169.254.169.254/icon.png'],
        ]));

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher($client)->download(self::ICON_URL);
    }

    public function testFollowsASafeRedirectToTheImage(): void
    {
        $responses = [
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => '/icon-2.png'],
            ]),
            new MockResponse('BINARY', [
                'response_headers' => ['content-type' => ['image/png']],
            ]),
        ];

        $icon = $this->fetcher(new MockHttpClient($responses))->download(self::ICON_URL);

        self::assertSame('BINARY', $icon->bytes);
        // sourceUrl stays the originally requested URL, not the redirect target.
        self::assertSame(self::ICON_URL, $icon->sourceUrl);
    }

    public function testRejectsAChainOfTooManyRedirects(): void
    {
        $redirect = static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'https://www.theverge.com/loop'],
        ]);
        $responses = [$redirect(), $redirect(), $redirect(), $redirect()];

        $this->expectException(FaviconUnavailableException::class);
        $this->fetcher(new MockHttpClient($responses))->download(self::ICON_URL);
    }

    public function testPinsTheConnectionToTheGuardValidatedIp(): void
    {
        $seenOptions = [];
        $factory = static function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse('BINARY', ['response_headers' => ['content-type' => ['image/png']]]);
        };

        $this->fetcher(new MockHttpClient($factory))->download(self::ICON_URL);

        self::assertSame(['www.theverge.com' => '93.184.216.34'], $seenOptions['resolve'] ?? null);
        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
    }
}
