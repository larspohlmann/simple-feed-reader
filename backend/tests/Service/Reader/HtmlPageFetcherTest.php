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
use App\Service\Reader\LandingChallenge;
use App\Service\Reader\MetaRefreshTarget;
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
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
        );
    }

    private function noProxyResolver(): ProxyEgressResolver
    {
        $resolver = $this->createStub(ProxyEgressResolver::class);
        $resolver->method('resolve')->willReturn(null);

        return $resolver;
    }

    private static function metaRefresh(string $target): string
    {
        return '<html><head><meta http-equiv="refresh" content="0; url=' . $target . '">'
            . '</head><body>interstitial</body></html>';
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

    public function testRejectsNon2xxWithTheStandardReasonPhrase(): void
    {
        $fetcher = $this->fetcher([new MockResponse('nope', ['http_code' => 404])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('HTTP 404 Not Found');
        $fetcher->fetch('https://example.com/missing');
    }

    public function testNon2xxAppendsTheVisibleTextOfTheErrorBody(): void
    {
        $body = '<html><head><style>.x{color:red}</style></head><body>'
            . '<h1>Access Denied</h1><script>var blocked = 1;</script>'
            . '<p>Your request was blocked.</p></body></html>';
        $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 403])]);

        try {
            $fetcher->fetch('https://example.com/blocked');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException $failure) {
            self::assertStringContainsString('HTTP 403 Forbidden', $failure->getMessage());
            self::assertStringContainsString('Access Denied', $failure->getMessage());
            self::assertStringContainsString('Your request was blocked.', $failure->getMessage());
            self::assertStringNotContainsString('color:red', $failure->getMessage());
            self::assertStringNotContainsString('var blocked', $failure->getMessage());
        }
    }

    public function testNon2xxWithAnEmptyBodyReportsOnlyTheStatus(): void
    {
        $fetcher = $this->fetcher([new MockResponse("   \n  ", ['http_code' => 404])]);

        try {
            $fetcher->fetch('https://example.com/missing');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException $failure) {
            self::assertSame('HTTP 404 Not Found', $failure->getMessage());
        }
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

        /** @var list<string> $headers */
        $headers = $seenOptions['headers'];
        self::assertContains(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            $headers,
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

    public function testFollowsAZeroDelayMetaRefreshToTheArticle(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse(self::metaRefresh('https://example.com/article'), ['http_code' => 200]),
            new MockResponse('<html><body>the real article</body></html>', ['http_code' => 200]),
        ]);

        $result = $fetcher->fetch('https://example.com/wall');

        self::assertStringContainsString('the real article', $result->html);
        self::assertSame('https://example.com/article', $result->finalUrl);
    }

    public function testLeavesANonZeroDelayMetaRefreshAlone(): void
    {
        $body = '<html><head><meta http-equiv="refresh" content="5; url=https://example.com/later">'
            . '</head><body>timed reload page</body></html>';
        $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

        $result = $fetcher->fetch('https://example.com/wall');

        self::assertStringContainsString('timed reload page', $result->html);
        self::assertSame('https://example.com/wall', $result->finalUrl);
    }

    public function testStopsAMetaRefreshSelfLoopAtTheBudget(): void
    {
        $fetcher = $this->fetcher(
            static fn (): MockResponse => new MockResponse(
                self::metaRefresh('https://example.com/wall'),
                ['http_code' => 200],
            ),
        );

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('more than 5 redirects');
        $fetcher->fetch('https://example.com/wall');
    }

    public function testSharesOneBudgetAcrossHttpRedirectAndMetaHops(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/a']]),
            new MockResponse(self::metaRefresh('https://example.com/b'), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/c']]),
            new MockResponse(self::metaRefresh('https://example.com/d'), ['http_code' => 200]),
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/e']]),
            new MockResponse(self::metaRefresh('https://example.com/f'), ['http_code' => 200]),
        ]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('more than 5 redirects');
        $fetcher->fetch('https://example.com/start');
    }

    public function testRejectsA200ChallengeBody(): void
    {
        $body = '<html><body class="cf-browser-verification">Just a moment…</body></html>';
        $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('https://example.com/wall');
    }

    public function testSsrfGuardsAMetaRefreshTarget(): void
    {
        $fetcher = $this->fetcher(
            [new MockResponse(self::metaRefresh('http://internal.test/secret'), ['http_code' => 200])],
            ['example.com' => ['93.184.216.34'], 'internal.test' => ['10.0.0.1']],
        );

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('https://example.com/wall');
    }

    public function testFollowsAMetaRefreshChainUpToTheFullBudget(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse(self::metaRefresh('https://example.com/2'), ['http_code' => 200]),
            new MockResponse(self::metaRefresh('https://example.com/3'), ['http_code' => 200]),
            new MockResponse(self::metaRefresh('https://example.com/4'), ['http_code' => 200]),
            new MockResponse(self::metaRefresh('https://example.com/5'), ['http_code' => 200]),
            new MockResponse(self::metaRefresh('https://example.com/6'), ['http_code' => 200]),
            new MockResponse('<html><body>the real article</body></html>', ['http_code' => 200]),
        ]);

        $result = $fetcher->fetch('https://example.com/1');

        self::assertStringContainsString('the real article', $result->html);
        self::assertSame('https://example.com/6', $result->finalUrl);
    }

    public function testDecodesABodyWhoseCharsetOnlyTheHeaderDeclares(): void
    {
        // #904: no <meta charset>, so the parser would otherwise read the
        // Windows-1252 bytes as UTF-8 and render mojibake.
        $fetcher = $this->fetcher([new MockResponse("<html lang=\"fr\"><body><p>Caf\xE9 cr\xE8me</p></body></html>", [
            'http_code' => 200,
            'response_headers' => ['content-type' => ['text/html; charset=windows-1252']],
        ])]);

        $result = $fetcher->fetch('https://example.com/post');

        self::assertStringContainsString('Café crème', $result->html);
    }

    public function testKeepsTheRawBodyWhenTheHeaderDeclaresUtf8(): void
    {
        $body = '<html lang="fr"><body><p>Café crème</p></body></html>';
        $fetcher = $this->fetcher([new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type' => ['text/html; charset=UTF-8']],
        ])]);

        self::assertSame($body, $fetcher->fetch('https://example.com/post')->html);
    }

    public function testKeepsTheRawBodyWhenTheHeaderCharsetIsUnknown(): void
    {
        $body = "<html lang=\"fr\"><body><p>Caf\xE9</p></body></html>";
        $fetcher = $this->fetcher([new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => ['content-type' => ['text/html; charset=x-nonsense']],
        ])]);

        self::assertSame($body, $fetcher->fetch('https://example.com/post')->html);
    }
}
