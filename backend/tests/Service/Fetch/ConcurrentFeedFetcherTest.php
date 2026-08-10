<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchTicket;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ConcurrentFeedFetcherTest extends TestCase
{
    /**
     * Every host in these tests resolves to one public address unless
     * $dnsOverrides says otherwise; the SSRF rules themselves are covered by
     * UrlGuard's own test.
     *
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsOverrides
     */
    private function fetcher(
        callable|iterable $responses,
        int $concurrency = 4,
        array $dnsOverrides = [],
    ): ConcurrentFeedFetcher {
        $resolver = new class ($dnsOverrides) implements DnsResolverInterface {
            /** @param array<string, list<string>> $overrides */
            public function __construct(private readonly array $overrides)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->overrides[$hostname]
                    ?? ('blocked.example.com' === $hostname ? [] : ['93.184.216.34']);
            }
        };

        return new ConcurrentFeedFetcher(
            new MockHttpClient($responses),
            new UrlGuard($resolver, new IpValidator()),
            new ResponseClassifier(new MockClock()),
            $concurrency,
            'TestAgent/1.0',
        );
    }

    /**
     * @param iterable<int|string, FetchOutcome> $outcomes
     *
     * @return array<int|string, FetchOutcome>
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
     * The cap is bound from a container parameter so the host can be dialled
     * down in config alone. A value below one opens no requests, which the
     * engine cannot tell apart from an empty batch — it has to fail loudly.
     */
    public function testRejectsAConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency must be at least 1, got 0.');

        $this->fetcher([], concurrency: 0);
    }

    public function testFetchesASingleTicket(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<rss/>', ['http_code' => 200])]);

        $outcomes = $this->collect($fetcher->fetchAll([7 => new FetchTicket('https://example.com/feed')]));

        self::assertCount(1, $outcomes);
        self::assertSame('<rss/>', $outcomes[7]->responseOrThrow()->body);
    }

    /**
     * A host whose IPv4 route is a blackhole but whose IPv6 route is healthy
     * (fazemag.de from Strato) must still connect. Pinning only the first
     * address defeats the client's cross-family fallback and times out; pinning
     * every validated address lets happy-eyeballs reach the live one.
     */
    public function testPinsEveryResolvedAddressSoTheClientCanFallBackAcrossFamilies(): void
    {
        /** @var array<string, string> $seenResolve */
        $seenResolve = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenResolve): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];
                $seenResolve = $resolve;

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            dnsOverrides: ['www.fazemag.de' => ['178.77.107.162', '2a01:488:42:1000:b24d:6ba2:1e:a1ad']],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://www.fazemag.de/feed/')]));

        self::assertNull($outcomes[1]->failure());
        self::assertSame(
            ['www.fazemag.de' => '178.77.107.162,2a01:488:42:1000:b24d:6ba2:1e:a1ad'],
            $seenResolve,
        );
    }

    /**
     * The mirror of the fazemag case: heise's IPv6 route from Strato completes
     * the TCP connect and then resets at the TLS handshake, which happy-eyeballs
     * cannot recover from because the connect already succeeded. The both-family
     * pin leads with IPv6, so the feed must fall over to an IPv4-only pin.
     */
    public function testFailsOverToIpv4WhenIpv6ConnectsButResetsBeforeHeaders(): void
    {
        /** @var list<string> $seenPins */
        $seenPins = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenPins): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];
                $pinnedAddresses = $resolve['www.heise.de'];
                $seenPins[] = $pinnedAddresses;

                return str_contains($pinnedAddresses, ':')
                    ? new MockResponse('', ['error' => 'Connection reset by peer'])
                    : new MockResponse('<rss/>', ['http_code' => 200]);
            },
            dnsOverrides: ['www.heise.de' => ['2a02:2e0:3fe:1001:7777:772e:2:85', '193.99.144.85']],
        );

        $ticket = new FetchTicket('https://www.heise.de/rss/heise-atom.xml');
        $outcomes = $this->collect($fetcher->fetchAll([1 => $ticket]));

        self::assertNull($outcomes[1]->failure());
        self::assertSame('<rss/>', $outcomes[1]->responseOrThrow()->body);
        self::assertSame(
            ['2a02:2e0:3fe:1001:7777:772e:2:85,193.99.144.85', '193.99.144.85'],
            $seenPins,
        );
    }

    public function testFailsOverThroughEveryFamilyUntilOneAnswers(): void
    {
        // Both the combined pin and the IPv4-only pin reset; only the IPv6-only
        // pin answers. The fetcher must walk the whole attempt list, not stop
        // after the first fallback.
        $ipv6 = '2a02:2e0:3fe:1001:7777:772e:2:85';
        $ipv4 = '193.99.144.85';
        /** @var list<string> $seenPins */
        $seenPins = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenPins, $ipv6): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];
                $pinnedAddresses = $resolve['www.heise.de'];
                $seenPins[] = $pinnedAddresses;

                return $pinnedAddresses === $ipv6
                    ? new MockResponse('<rss/>', ['http_code' => 200])
                    : new MockResponse('', ['error' => 'Connection reset by peer']);
            },
            dnsOverrides: ['www.heise.de' => [$ipv6, $ipv4]],
        );

        $ticket = new FetchTicket('https://www.heise.de/rss/heise-atom.xml');
        $outcomes = $this->collect($fetcher->fetchAll([1 => $ticket]));

        self::assertSame('<rss/>', $outcomes[1]->responseOrThrow()->body);
        self::assertSame([$ipv6 . ',' . $ipv4, $ipv4, $ipv6], $seenPins);
    }

    public function testFailsOverToIpv4WhenIpv6AnswersWithAnErrorStatus(): void
    {
        // taz.de forbids its IPv6 range from Strato (403) while IPv4 serves 200;
        // the error status must fall over to the family that answers.
        $ipv6 = '2a02:2e0:3fe:1001:7777:772e:2:85';
        $ipv4 = '193.99.144.85';
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use ($ipv4): MockResponse {
                /** @var array<string, string> $resolve */
                $resolve = $options['resolve'];

                return $resolve['taz.de'] === $ipv4
                    ? new MockResponse('<rss/>', ['http_code' => 200])
                    : new MockResponse('forbidden', ['http_code' => 403]);
            },
            dnsOverrides: ['taz.de' => [$ipv6, $ipv4]],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://taz.de/!p4608;rss/')]));

        self::assertSame('<rss/>', $outcomes[1]->responseOrThrow()->body);
    }

    public function testAnOversizedBodyIsNotFailedOverToAnotherFamily(): void
    {
        // The cap would be exceeded on any family, so a second request is pure
        // waste: the oversized outcome stands after a single attempt.
        $requestCount = 0;
        $fetcher = $this->fetcher(
            function () use (&$requestCount): MockResponse {
                ++$requestCount;

                return new MockResponse(str_repeat('x', 6_000_000), ['http_code' => 200]);
            },
            dnsOverrides: ['dual.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://dual.example.com/feed')]));

        self::assertInstanceOf(ResponseTooLargeException::class, $outcomes[1]->failure());
        self::assertSame(1, $requestCount);
    }

    public function testATimeoutIsNotFailedOverToAnotherFamily(): void
    {
        // A timeout means the family answered the connect but is slow, not that
        // the route is dead. Even with a second family available, re-driving would
        // only multiply the wait, so the timeout stands as the outcome.
        $requestCount = 0;
        $fetcher = $this->fetcher(
            function () use (&$requestCount): MockResponse {
                ++$requestCount;
                $body = (static function (): \Generator {
                    yield '<rss';
                    yield '';
                })();

                return new MockResponse($body, ['http_code' => 200]);
            },
            dnsOverrides: ['dual.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']],
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://dual.example.com/feed')]));

        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
        self::assertSame(1, $requestCount);
    }

    public function testASingleFamilyTransportFailureIsNotRetried(): void
    {
        $requestCount = 0;
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$requestCount): MockResponse {
                ++$requestCount;

                return new MockResponse('', ['error' => 'Connection reset by peer']);
            },
        );

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
        // One family, so nothing to fall back to: exactly one request, no retry loop.
        self::assertSame(1, $requestCount);
    }

    public function testFetchesEveryTicketInABatch(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<rss><channel><title>' . $url . '</title></channel></rss>',
            ['http_code' => 200],
        ));

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://one.example.com/feed'),
            2 => new FetchTicket('https://two.example.com/feed'),
            3 => new FetchTicket('https://three.example.com/feed'),
            4 => new FetchTicket('https://four.example.com/feed'),
            5 => new FetchTicket('https://five.example.com/feed'),
        ]));

        self::assertCount(5, $outcomes);
        foreach ([1, 2, 3, 4, 5] as $key) {
            self::assertNull($outcomes[$key]->failure(), sprintf('ticket %d should have succeeded', $key));
        }
        // Concurrency is 4, so the fifth only starts once a slot frees.
        self::assertStringContainsString('five.example.com', (string) $outcomes[5]->responseOrThrow()->body);
    }

    public function testOneFailureDoesNotAbandonTheRestOfTheBatch(): void
    {
        $fetcher = $this->fetcher(static fn (string $method, string $url): MockResponse => str_contains($url, 'bad')
            ? new MockResponse('', ['http_code' => 500])
            : new MockResponse('<rss/>', ['http_code' => 200]));

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://good.example.com/feed'),
            2 => new FetchTicket('https://bad.example.com/feed'),
            3 => new FetchTicket('https://alsogood.example.com/feed'),
        ]));

        self::assertCount(3, $outcomes);
        self::assertNull($outcomes[1]->failure());
        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[2]->failure());
        self::assertNull($outcomes[3]->failure());
    }

    public function testAGoneFeedIsReportedAsAnOutcomeNotAThrow(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => 410])]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertInstanceOf(FeedGoneException::class, $outcomes[1]->failure());
    }

    public function testAnSsrfBlockIsReportedAsAnOutcomeNotAThrow(): void
    {
        $fetcher = $this->fetcher([]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://blocked.example.com/feed')]));

        self::assertInstanceOf(SsrfBlockedException::class, $outcomes[1]->failure());
    }

    public function testFollowsARedirectChainAndReportsItPermanent(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://example.com/one']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://example.com/two']]),
            new MockResponse('<rss/>', ['http_code' => 200]),
        ]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $response = $outcomes[1]->responseOrThrow();
        self::assertSame('https://example.com/two', $response->finalUrl);
        self::assertTrue($response->permanentRedirect);
        self::assertSame('<rss/>', $response->body);
    }

    /**
     * SECURITY: the guard runs per hop, not once per ticket. A feed that passes
     * on its first URL must not be able to redirect the fetcher onto a host the
     * guard would have rejected.
     */
    public function testARedirectOntoABlockedHostIsGuardedAgain(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://blocked.example.com/feed'],
            ]),
        ]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertInstanceOf(SsrfBlockedException::class, $outcomes[1]->failure());
    }

    public function testARedirectLoopIsCutOffAfterFiveHops(): void
    {
        $fetcher = $this->fetcher(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'https://example.com/next'],
        ]));

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $failure = $outcomes[1]->failure();
        self::assertInstanceOf(FeedUnreachableException::class, $failure);
        self::assertStringContainsString('more than 5 redirects', $failure->getMessage());
    }

    public function testAFeedStillRedirectingDoesNotBlockOthersFromCompleting(): void
    {
        $redirects = 0;
        $requested = [];
        $fetcher = $this->fetcher(
            static function (string $method, string $url) use (&$redirects, &$requested): MockResponse {
                $requested[] = $url;

                if (str_contains($url, 'slow')) {
                    $redirects++;

                    return new MockResponse('', [
                        'http_code' => 302,
                        'response_headers' => ['location' => 'https://slow.example.com/hop' . $redirects],
                    ]);
                }

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            concurrency: 2,
        );

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://slow.example.com/feed'),
            2 => new FetchTicket('https://fast.example.com/feed'),
        ]));

        self::assertNull($outcomes[2]->failure());
        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());

        // The real claim: the fast feed goes on the wire second, while the slow
        // one is only one hop into its chain. Run serially it would wait out all
        // six of the slow feed's hops and be requested seventh instead, so this
        // ordering — not the fact that both terminate — is what proves overlap.
        self::assertSame(
            ['https://slow.example.com/feed', 'https://fast.example.com/feed'],
            \array_slice($requested, 0, 2),
        );
    }

    public function testAnIdleTimeoutMidResponseIsReportedAsAFailure(): void
    {
        // MockResponse turns an empty yield into an idle-timeout chunk.
        $body = (static function (): \Generator {
            yield '<rss';
            yield '';
        })();

        $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $failure = $outcomes[1]->failure();
        self::assertInstanceOf(FeedUnreachableException::class, $failure);
        self::assertStringContainsString('timed out', $failure->getMessage());
    }

    public function testATransportErrorMidBodyIsReportedAsAFailure(): void
    {
        $body = (static function (): \Generator {
            yield '<rss';
            yield new TransportException('connection reset by peer');
        })();

        $fetcher = $this->fetcher([new MockResponse($body, ['http_code' => 200])]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        $failure = $outcomes[1]->failure();
        self::assertInstanceOf(FeedUnreachableException::class, $failure);
        self::assertStringContainsString('connection reset by peer', $failure->getMessage());
    }

    /**
     * The size cap is enforced from inside on_progress, so the client wraps it;
     * the outcome must still carry the real cause rather than the wrapper.
     */
    public function testAnOversizedBodyIsReportedAsTooLarge(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse(str_repeat('x', 6_000_000), ['http_code' => 200]),
        ]);

        $outcomes = $this->collect($fetcher->fetchAll([1 => new FetchTicket('https://example.com/feed')]));

        self::assertInstanceOf(ResponseTooLargeException::class, $outcomes[1]->failure());
    }

    public function testSendsConditionalGetHeaders(): void
    {
        $seenOptions = [];
        $fetcher = $this->fetcher(
            static function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions = $options;

                return new MockResponse('', ['http_code' => 304]);
            },
        );

        $outcomes = $this->collect($fetcher->fetchAll([
            1 => new FetchTicket('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT'),
        ]));

        self::assertTrue($outcomes[1]->responseOrThrow()->notModified);

        // Only the field name is case-insensitive. The value is compared as sent,
        // so a mangled HTTP-date cannot pass for a well-formed one.
        $headers = [];
        foreach ((array) ($seenOptions['headers'] ?? []) as $header) {
            if (!\is_string($header)) {
                continue;
            }
            $parts = explode(': ', $header, 2);
            if (2 === \count($parts)) {
                $headers[strtolower($parts[0])] = $parts[1];
            }
        }
        self::assertSame('"v1"', $headers['if-none-match'] ?? null);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $headers['if-modified-since'] ?? null);
    }

    public function testAbandoningTheGeneratorCancelsWhatIsStillInFlight(): void
    {
        $started = 0;
        $fetcher = $this->fetcher(
            static function () use (&$started): MockResponse {
                $started++;

                return new MockResponse('<rss/>', ['http_code' => 200]);
            },
            concurrency: 3,
        );

        $tickets = [
            1 => new FetchTicket('https://one.example.com/feed'),
            2 => new FetchTicket('https://two.example.com/feed'),
            3 => new FetchTicket('https://three.example.com/feed'),
            4 => new FetchTicket('https://four.example.com/feed'),
            5 => new FetchTicket('https://five.example.com/feed'),
        ];

        foreach ($fetcher->fetchAll($tickets) as $outcome) {
            self::assertNull($outcome->failure());
            break;
        }

        // Three slots were filled; the fourth and fifth tickets were never
        // pulled, so an aborted run stops making requests instead of draining
        // the whole batch.
        self::assertSame(3, $started);
    }
}
