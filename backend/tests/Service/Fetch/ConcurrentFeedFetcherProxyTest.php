<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use App\Service\Crypto\Exception\SecretUnreadableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConcurrentFeedFetcherProxyTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsOverrides
     */
    private function fetcher(
        callable|iterable $responses,
        ?ProxyConfig $resolvedProxy,
        array $dnsOverrides = [],
    ): ConcurrentFeedFetcher {
        $resolver = $this->dns($dnsOverrides);

        $proxyEgressResolver = $this->createMock(ProxyEgressResolver::class);
        $proxyEgressResolver->method('resolve')->willReturn($resolvedProxy);

        $urlGuard = new UrlGuard($resolver, new IpValidator());

        return new ConcurrentFeedFetcher(
            new MockHttpClient($responses),
            $urlGuard,
            new ResponseClassifier(new MockClock()),
            4,
            'TestAgent/1.0',
            $proxyEgressResolver,
            new FetchRetryPolicy($urlGuard),
        );
    }

    /** @param array<string, list<string>> $dnsOverrides */
    private function dns(array $dnsOverrides = []): DnsResolverInterface
    {
        return new class ($dnsOverrides) implements DnsResolverInterface {
            /** @param array<string, list<string>> $overrides */
            public function __construct(private readonly array $overrides)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->overrides[$hostname] ?? ['93.184.216.34'];
            }
        };
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

    /**
     * The sweep's `remaining` only decrements on a yielded outcome, so letting
     * the resolver's failure escape would strand the whole run rather than
     * report it. Every feed comes back failed instead.
     */
    public function testAnUnreadableProxyPasswordFailsEveryFeedInsteadOfAbortingTheSweep(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen[] = $u;

            return new MockResponse('ok');
        });
        $proxyEgressResolver = $this->createMock(ProxyEgressResolver::class);
        $proxyEgressResolver->method('resolve')->willThrowException(
            new SecretUnreadableException('The stored secret failed its integrity check.'),
        );
        $urlGuard = new UrlGuard($this->dns(), new IpValidator());
        $fetcher = new ConcurrentFeedFetcher(
            $client,
            $urlGuard,
            new ResponseClassifier(new MockClock()),
            4,
            'TestAgent/1.0',
            $proxyEgressResolver,
            new FetchRetryPolicy($urlGuard),
        );

        $outcomes = $this->collect($fetcher->fetchAll([
            7 => new FetchTicket('https://one.example/feed'),
            9 => new FetchTicket('https://two.example/feed'),
        ]));

        self::assertSame([7, 9], array_keys($outcomes));
        foreach ($outcomes as $outcome) {
            self::assertNotNull($outcome->failure());
        }
        self::assertSame([], $seen, 'nothing may go out while the egress is unusable');
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
        self::assertSame('socks5://p:1080', $seenOptions[0]['proxy']);
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
        self::assertSame('socks5://p:1080', $seenOptions[0]['proxy']);
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
        self::assertSame('socks5://p:1080', $seenOptions[0]['proxy']);
    }

    /**
     * SECURITY: a dual-stack host must not let the cross-family retry smuggle a
     * still-proxied attempt back onto the wire. With directFallback off, the
     * proxied failure has to be terminal after exactly one request, regardless
     * of how many address families the guard could otherwise walk through.
     */
    public function testProxiedFailureOnADualStackHostIsTerminalWhenDirectFallbackIsDisabled(): void
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
            dnsOverrides: ['dual.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://dual.example.com/feed')]));

        self::assertNotNull($outcomes[1]->failure());
        self::assertCount(1, $seenOptions);
        self::assertSame('socks5://p:1080', $seenOptions[0]['proxy']);
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
