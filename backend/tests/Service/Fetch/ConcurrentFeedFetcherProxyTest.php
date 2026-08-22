<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConcurrentFeedFetcherProxyTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     */
    private function fetcher(callable|iterable $responses, ?ProxyConfig $resolvedProxy): ConcurrentFeedFetcher
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        $proxyEgressResolver = $this->createMock(ProxyEgressResolver::class);
        $proxyEgressResolver->method('resolve')->willReturn($resolvedProxy);

        return new ConcurrentFeedFetcher(
            new MockHttpClient($responses),
            new UrlGuard($resolver, new IpValidator()),
            new ResponseClassifier(new MockClock()),
            4,
            'TestAgent/1.0',
            $proxyEgressResolver,
        );
    }

    /**
     * @param iterable<int|string, \App\Service\Fetch\FetchOutcome> $outcomes
     *
     * @return array<int|string, \App\Service\Fetch\FetchOutcome>
     */
    private function collect(iterable $outcomes): array
    {
        $collected = [];
        foreach ($outcomes as $key => $outcome) {
            $collected[$key] = $outcome;
        }

        return $collected;
    }

    public function testEnabledResolverProxiesPlainTickets(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);
        /** @var list<array<string, mixed>> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions[] = $options;

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            $proxy,
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertNull($outcomes[1]->failure());
        self::assertCount(1, $seenOptions);
        self::assertSame('socks5h://p:1080', $seenOptions[0]['proxy']);
        self::assertArrayNotHasKey('resolve', $seenOptions[0]);
    }

    public function testProxiedFailureRetriesExactlyOneDirectAttempt(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);
        /** @var list<array<string, mixed>> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions[] = $options;

                return isset($options['proxy'])
                    ? new MockResponse('', ['error' => 'Connection reset by peer'])
                    : new MockResponse('<rss/>', ['http_code' => 200]);
            },
            $proxy,
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertNull($outcomes[1]->failure());
        self::assertCount(2, $seenOptions);
        self::assertSame('socks5h://p:1080', $seenOptions[0]['proxy']);
        self::assertArrayNotHasKey('resolve', $seenOptions[0]);
        self::assertArrayNotHasKey('proxy', $seenOptions[1]);
        self::assertArrayHasKey('resolve', $seenOptions[1]);
    }

    public function testProxiedFailureIsTerminalWhenDirectFallbackIsDisabled(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null, false);
        /** @var list<array<string, mixed>> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions[] = $options;

                return new MockResponse('', ['error' => 'Connection reset by peer']);
            },
            $proxy,
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertNotNull($outcomes[1]->failure());
        self::assertCount(1, $seenOptions);
        self::assertSame('socks5h://p:1080', $seenOptions[0]['proxy']);
    }

    public function testDirectWhenResolverReturnsNull(): void
    {
        /** @var list<array<string, mixed>> $seenOptions */
        $seenOptions = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions[] = $options;

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            null,
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertNull($outcomes[1]->failure());
        self::assertCount(1, $seenOptions);
        self::assertArrayHasKey('resolve', $seenOptions[0]);
        self::assertArrayNotHasKey('proxy', $seenOptions[0]);
    }
}
