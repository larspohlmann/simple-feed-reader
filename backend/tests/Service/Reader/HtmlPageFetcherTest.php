<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HtmlPageFetcherTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function fetcher(
        callable|iterable $responses,
        array $dnsMap = ['example.com' => ['93.184.216.34']],
    ): HtmlPageFetcher {
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

        return new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            'TestAgent/1.0',
        );
    }

    private function noProxyResolver(): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return $resolver;
    }

    public function testReturnsBodyAndFinalUrlOnSuccess(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<html lang="en"><body>hi</body></html>', [
            'http_code' => 200,
        ])]);

        $result = $fetcher->fetch('https://example.com/post');

        self::assertStringContainsString('hi', $result->html);
        self::assertSame('https://example.com/post', $result->finalUrl);
    }

    public function testWrapsSsrfBlockInPageFetchException(): void
    {
        // Link-local IP literal (169.254.0.0/16) is rejected by the guard.
        $fetcher = $this->fetcher([]);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsNon2xx(): void
    {
        $fetcher = $this->fetcher([new MockResponse('nope', ['http_code' => 404])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('HTTP 404');
        $fetcher->fetch('https://example.com/missing');
    }

    public function testSendsTheAcceptHeaderAndTimeBudgetForEveryFetch(): void
    {
        /** @var array<string, mixed> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions = $options;

                return new MockResponse('<html lang="en"><body>ok</body></html>', ['http_code' => 200]);
            },
        );

        $fetcher->fetch('https://example.com/post');

        self::assertContains(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            $seenOptions['headers'],
        );
        self::assertSame(10.0, $seenOptions['timeout']);
        self::assertSame(20.0, $seenOptions['max_duration']);
        self::assertSame(0, $seenOptions['max_redirects']);
    }

    public function testDisablesTransparentCompression(): void
    {
        /** @var list<string> $seenHeaders */
        $seenHeaders = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
                /** @var list<string> $headers */
                $headers = $options['headers'];
                $seenHeaders = $headers;

                return new MockResponse('<html lang="en"><body>ok</body></html>', ['http_code' => 200]);
            },
        );

        $fetcher->fetch('https://example.com/post');

        // Without this, curl would negotiate gzip and the on_progress byte cap
        // would count compressed bytes while the decompressed body is buffered
        // whole — a decompression-bomb amplification.
        self::assertContains('Accept-Encoding: identity', $seenHeaders);
    }

    public function testFailsOverToIpv4WhenIpv6ConnectsButResetsBeforeHeaders(): void
    {
        // The both-families pin leads with IPv6. From Strato heise's IPv6 route
        // completes the TCP connect and then resets at the TLS handshake, which
        // happy-eyeballs cannot recover from — the article must still load over
        // IPv4.
        $fetcher = $this->fetcher(
            static function (string $method, string $url, array $options): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];
                $pinnedAddresses = $resolve['dual.example.com'];

                return str_contains($pinnedAddresses, ':')
                    ? new MockResponse('', ['error' => 'Connection reset by peer'])
                    : new MockResponse('<html lang="en"><body>article</body></html>', ['http_code' => 200]);
            },
            ['dual.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
        );

        $result = $fetcher->fetch('https://dual.example.com/post');

        self::assertStringContainsString('article', $result->html);
    }

    public function testPinsEveryResolvedAddressSoTheClientCanFallBackAcrossFamilies(): void
    {
        /** @var array<string, string> $seenResolve */
        $seenResolve = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenResolve): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];
                $seenResolve = $resolve;

                return new MockResponse('<html lang="en"><body>ok</body></html>', ['http_code' => 200]);
            },
            ['dual.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']],
        );

        $fetcher->fetch('https://dual.example.com/post');

        self::assertSame(
            ['dual.example.com' => '93.184.216.34,2606:2800:220:1:248:1893:25c8:1946'],
            $seenResolve,
        );
    }
}
