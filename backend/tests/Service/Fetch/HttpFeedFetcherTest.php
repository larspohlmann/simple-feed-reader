<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\HttpFeedFetcher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpFeedFetcherTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function fetcher(
        callable|iterable $responses,
        array $dnsMap = ['example.com' => ['93.184.216.34']],
    ): HttpFeedFetcher {
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

        return new HttpFeedFetcher(new MockHttpClient($responses), new UrlGuard($resolver, new IpValidator()));
    }

    public function testFetchesBodyAndCachingHeaders(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<rss/>', [
            'http_code' => 200,
            'response_headers' => ['etag' => '"v1"', 'last-modified' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
        ])]);

        $response = $fetcher->fetch('https://example.com/feed');

        self::assertFalse($response->notModified);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('"v1"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
        self::assertSame('https://example.com/feed', $response->finalUrl);
    }

    public function testSendsConditionalGetHeaders(): void
    {
        $seenOptions = [];
        $factory = static function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse('', ['http_code' => 304]);
        };

        $response = $this->fetcher($factory)
            ->fetch('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        self::assertTrue($response->notModified);
        $headers = [];
        foreach ((array) ($seenOptions['headers'] ?? []) as $header) {
            if (\is_string($header)) {
                $headers[] = strtolower($header);
            }
        }
        self::assertContains('if-none-match: "v1"', $headers);
        self::assertContains('if-modified-since: mon, 20 jul 2026 08:30:00 gmt', $headers);
    }

    public function testPinsConnectionToGuardValidatedIp(): void
    {
        $seenOptions = [];
        $factory = static function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            $seenOptions = $options;

            return new MockResponse('<rss/>', ['http_code' => 200]);
        };

        $this->fetcher($factory)->fetch('https://example.com/feed');

        self::assertSame(['example.com' => '93.184.216.34'], $seenOptions['resolve'] ?? null);
        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
    }

    public function testFollowsRedirectAndReportsPermanentMove(): void
    {
        $responses = [
            new MockResponse('', [
                'http_code' => 301,
                'response_headers' => ['location' => 'https://example.com/new-feed'],
            ]),
            new MockResponse('<rss/>', ['http_code' => 200]),
        ];

        $response = $this->fetcher($responses)->fetch('https://example.com/old-feed');

        self::assertTrue($response->permanentRedirect);
        self::assertSame('https://example.com/new-feed', $response->finalUrl);
        self::assertSame('<rss/>', $response->body);
    }

    public function testRevalidatesRedirectTargetAgainstGuard(): void
    {
        $responses = [
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://169.254.169.254/latest'],
            ]),
        ];

        $this->expectException(SsrfBlockedException::class);
        $this->fetcher($responses)->fetch('https://example.com/feed');
    }

    public function testResolvesRelativeRedirectAgainstCurrentUrl(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/moved/feed.xml']]),
            new MockResponse('<rss/>', ['http_code' => 200]),
        ];

        $response = $this->fetcher($responses)->fetch('https://example.com/a/b/feed');

        self::assertSame('https://example.com/moved/feed.xml', $response->finalUrl);
    }

    public function testTooManyRedirects(): void
    {
        $redirect = static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'https://example.com/loop'],
        ]);
        $responses = [$redirect(), $redirect(), $redirect(), $redirect(), $redirect(), $redirect(), $redirect()];

        $this->expectException(FeedUnreachableException::class);
        $this->fetcher($responses)->fetch('https://example.com/feed');
    }

    public function testHttp410ThrowsFeedGone(): void
    {
        $this->expectException(FeedGoneException::class);
        $this->fetcher([new MockResponse('', ['http_code' => 410])])->fetch('https://example.com/feed');
    }

    public function testHttp500ThrowsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->fetcher([new MockResponse('oops', ['http_code' => 500])])->fetch('https://example.com/feed');
    }

    public function testOversizedResponseThrows(): void
    {
        $body = str_repeat('a', 5_000_001);

        $this->expectException(ResponseTooLargeException::class);
        $this->fetcher([new MockResponse($body, ['http_code' => 200])])->fetch('https://example.com/feed');
    }

    public function testNetworkErrorThrowsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->fetcher([new MockResponse('', ['error' => 'connection refused'])])->fetch('https://example.com/feed');
    }
}
