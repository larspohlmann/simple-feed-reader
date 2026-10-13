<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\ResponseExchange\FeedResponseExchange;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FeedTickets;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\FixedKeySlot;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConcurrentRequestEngineTest extends TestCase
{
    private const string API = 'https://api.example.test/timeline';
    private const string OTHER = 'https://other.example.test/timeline';
    private const string DUAL = 'https://dual.example.test/timeline';
    private const string FEED = 'https://other.example.test/feed';
    private const string IPV6 = '2a02:2e0:3fe:1001:7777:772e:2:85';
    private const string IPV4 = '193.99.144.85';

    private static function guard(): UrlGuard
    {
        return new UrlGuard(new StaticDnsResolver([
            'api.example.test' => ['93.184.216.34'],
            'other.example.test' => ['93.184.216.35'],
            'dual.example.test' => [self::IPV6, self::IPV4],
        ]), new IpValidator());
    }

    private function engine(HttpClientInterface $client, ?ProxyConfigModel $proxy = null): ConcurrentRequestEngine
    {
        return FetchWiring::requestEngine($client, self::guard(), FetchWiring::egressProxy($proxy));
    }

    private static function ticket(
        string $url = self::API,
        string $slotName = 'account-1',
        int $capacity = 3,
    ): RequestTicketModel {
        return RequestTicketModel::api(
            ApiRequestModel::get($url, ['Authorization' => 'Bearer jwt-1']),
            [new FixedKeySlot($slotName, $capacity)],
        );
    }

    /**
     * @param iterable<int|string, FetchOutcomeModel> $outcomes
     *
     * @return array<int|string, FetchOutcomeModel>
     */
    private static function collect(iterable $outcomes): array
    {
        $collected = [];
        foreach ($outcomes as $key => $outcome) {
            $collected[$key] = $outcome;
        }

        return $collected;
    }

    private function onlyAnswer(HttpClientInterface $client, string $url = self::API): FetchOutcomeModel
    {
        return self::collect($this->engine($client)->sendAll([1 => self::ticket($url)]))[1];
    }

    public function testAnErrorStatusIsAnAnswerWithItsBodyAndHeaders(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"RateLimitExceeded"}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '120'],
        ])]);

        $answer = $this->onlyAnswer($client)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame('{"error":"RateLimitExceeded"}', $answer->body);
        self::assertSame('120', $answer->header('retry-after'));
    }

    public function testARedirectIsAnAnswerAndNeverFollowed(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['Location' => self::OTHER],
        ])]);

        $answer = $this->onlyAnswer($http->client())->answerOrThrow();

        self::assertSame(Response::HTTP_FOUND, $answer->status);
        self::assertSame([self::API], $http->requestedUrls());
    }

    public function testAnErrorStatusIsAskedAgainOverTheNextFamily(): void
    {
        $respond = static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return self::IPV4 === $resolve['dual.example.test']
                ? new MockResponse('{"feed":[]}')
                : new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
        };

        $answer = $this->onlyAnswer(new MockHttpClient($respond), self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
    }

    public function testTheLastFamilysErrorStatusStandsAsTheAnswer(): void
    {
        /** @var list<string> $pins */
        $pins = [];
        $respond = static function (string $method, string $url, array $options) use (&$pins): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $pins[] = $resolve['dual.example.test'];

            return new MockResponse('{"error":"InvalidToken"}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
        };

        $answer = $this->onlyAnswer(new MockHttpClient($respond), self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $answer->status);
        self::assertSame('{"error":"InvalidToken"}', $answer->body);
        self::assertSame([self::IPV6 . ',' . self::IPV4, self::IPV4, self::IPV6], $pins);
    }

    public function testAMissingResourceIsNotAskedAgain(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]);
        });

        self::assertSame(Response::HTTP_NOT_FOUND, $this->onlyAnswer($client, self::DUAL)->answerOrThrow()->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedRefusalIsAskedOnceDirectly(): void
    {
        /** @var list<bool> $proxied */
        $proxied = [];
        $respond = static function (string $method, string $url, array $options) use (&$proxied): MockResponse {
            $proxied[] = isset($options['proxy']);

            return isset($options['proxy'])
                ? new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN])
                : new MockResponse('[]');
        };
        $engine = $this->engine(
            new MockHttpClient($respond),
            new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null),
        );

        $answer = self::collect($engine->sendAll([1 => self::ticket()]))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame([true, false], $proxied);
    }

    public function testAProxiedRefusalStandsWhenDirectFallbackIsOff(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null, false));

        $answer = self::collect($engine->sendAll([1 => self::ticket()]))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_FORBIDDEN, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedResetIsAskedOnceDirectly(): void
    {
        /** @var list<bool> $proxied */
        $proxied = [];
        $respond = static function (string $method, string $url, array $options) use (&$proxied): MockResponse {
            $proxied[] = isset($options['proxy']);

            return isset($options['proxy'])
                ? new MockResponse('', ['error' => 'Connection reset by peer'])
                : new MockResponse('[]');
        };
        $engine = $this->engine(
            new MockHttpClient($respond),
            new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null),
        );

        $answer = self::collect($engine->sendAll([1 => self::ticket()]))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame([true, false], $proxied);
    }

    public function testSendsTheTicketsMethodHeadersAndJsonBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::API => new MockResponse('{}')]);
        $request = ApiRequestModel::postJson(
            self::API,
            ['identifier' => 'did:plc:alice'],
            ['Authorization' => 'Bearer jwt-1'],
        );
        $ticket = RequestTicketModel::api($request, [new FixedKeySlot('account-1', 3)]);

        self::assertNull(self::collect($this->engine($http->client())->sendAll([1 => $ticket]))[1]->failure());

        self::assertSame('{"identifier":"did:plc:alice"}', $http->requests[0]['body']);
        self::assertContains('Accept: application/json', $http->requests[0]['headers']);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[0]['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $http->requests[0]['headers']);
    }

    public function testAGetSendsNoBody(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);

        $this->onlyAnswer($http->client());

        self::assertSame('', $http->requests[0]['body']);
        self::assertNotContains('Content-Type: application/json', $http->requests[0]['headers']);
    }

    public function testATicketCannotOverrideTheHeadersTheEngineOwns(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $ticket = RequestTicketModel::api(ApiRequestModel::get(self::API, [
            'accept-encoding' => 'gzip',
            'User-Agent' => 'Spoofed/1.0',
            'Accept' => 'text/html',
            'Authorization' => 'Bearer jwt-1',
        ]), [new FixedKeySlot('account-1', 3)]);

        self::assertNull(self::collect($this->engine($http->client())->sendAll([1 => $ticket]))[1]->failure());

        $sent = $http->requests[0]['headers'];
        self::assertContains('Accept-Encoding: identity', $sent);
        self::assertContains('User-Agent: TestAgent/1.0', $sent);
        self::assertContains('Accept: application/json', $sent);
        self::assertContains('Authorization: Bearer jwt-1', $sent);
        self::assertNotContains('accept-encoding: gzip', $sent);
        self::assertNotContains('User-Agent: Spoofed/1.0', $sent);
        self::assertNotContains('Accept: text/html', $sent);
    }

    public function testANamedSlotCapsItsRequestsWhateverTheirHost(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            ++$inFlight;
            $peak = max($peak, $inFlight);
            $body = (static function () use (&$inFlight): \Generator {
                yield '{}';
                --$inFlight;
            })();

            return new MockResponse($body);
        });

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => self::ticket(self::API, 'account-1', 1),
            2 => self::ticket(self::OTHER, 'account-1', 1),
            3 => self::ticket(self::API, 'account-1', 1),
        ]));

        self::assertCount(3, $outcomes);
        self::assertSame(1, $peak);
    }

    public function testATicketGoesOutOnlyWhileEverySlotItNamesHasRoom(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            ++$inFlight;
            $peak = max($peak, $inFlight);
            $body = (static function () use (&$inFlight): \Generator {
                yield '{}';
                --$inFlight;
            })();

            return new MockResponse($body);
        });
        $onTheSharedServer = static fn (string $account): RequestTicketModel => RequestTicketModel::api(
            ApiRequestModel::get(self::API),
            [new FixedKeySlot($account, 3), new FixedKeySlot('server', 1)],
        );

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => $onTheSharedServer('account-1'),
            2 => $onTheSharedServer('account-2'),
            3 => $onTheSharedServer('account-3'),
        ]));

        self::assertCount(3, $outcomes, 'every slot a ticket held is released when it retires');
        self::assertSame(1, $peak, 'three accounts with room share one full server slot');
    }

    public function testAWithheldTicketGoesNowhere(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $gate = new class () implements DispatchGateInterface {
            public function admits(int|string $key): bool
            {
                return 1 !== $key;
            }
        };

        $outcomes = self::collect($this->engine($http->client())->sendAll(
            [1 => self::ticket(self::OTHER, 'account-2'), 2 => self::ticket(self::API)],
            $gate,
        ));

        self::assertTrue($outcomes[1]->isWithheld());
        self::assertFalse($outcomes[2]->isWithheld());
        self::assertSame([self::API], $http->requestedUrls());
    }

    public function testTheGateDecidesWhenAParkedTicketWouldGoOut(): void
    {
        $gate = new class () implements DispatchGateInterface {
            public bool $open = true;

            public function admits(int|string $key): bool
            {
                return $this->open;
            }
        };
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);

        $outcomes = [];
        $sending = $this->engine($http->client())->sendAll(
            [1 => self::ticket(capacity: 1), 2 => self::ticket(capacity: 1)],
            $gate,
        );
        foreach ($sending as $key => $outcome) {
            $outcomes[$key] = $outcome;
            $gate->open = false;
        }

        self::assertFalse($outcomes[1]->isWithheld());
        self::assertTrue($outcomes[2]->isWithheld());
        self::assertCount(1, $http->requests);
    }

    public function testATicketAlreadyOnTheWireIsAskedAgainWithoutTheGate(): void
    {
        $gate = new class () implements DispatchGateInterface {
            public int $asked = 0;

            public function admits(int|string $key): bool
            {
                ++$this->asked;

                return 1 === $this->asked;
            }
        };
        $respond = static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return self::IPV4 === $resolve['dual.example.test']
                ? new MockResponse('{"feed":[]}')
                : new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
        };

        $outcomes = self::collect(
            $this->engine(new MockHttpClient($respond))->sendAll([1 => self::ticket(self::DUAL)], $gate),
        );

        self::assertSame(Response::HTTP_OK, $outcomes[1]->answerOrThrow()->status);
        self::assertSame(1, $gate->asked);
    }

    public function testAnOversizedAnswerFailsAtTheApiLimit(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('x', ResponseSizeLimit::Api->bytes() + 1))]);

        self::assertInstanceOf(ResponseTooLargeException::class, $this->onlyAnswer($client)->failure());
    }

    public function testATicketNoExchangeReadsFailsAloneAndTheOthersStillLand(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::API => new MockResponse('{}'),
            'GET ' . self::FEED => new MockResponse('<rss/>', ['response_headers' => ['Content-Type' => 'text/xml']]),
        ]);
        $feedOnly = new ServiceLocator([
            FeedResponseExchange::kind() => static fn (): FeedResponseExchange => new FeedResponseExchange(
                new ResponseClassifier(new MockClock()),
            ),
        ]);
        $engine = new ConcurrentRequestEngine(
            $http->client(),
            self::guard(),
            FetchWiring::egressProxy(null),
            FetchWiring::retryPolicy(self::guard()),
            $feedOnly,
            4,
            'TestAgent/1.0',
        );

        $outcomes = self::collect($engine->sendAll([1 => self::ticket(), 2 => FeedTickets::of(self::FEED)]));

        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
        self::assertNull($outcomes[2]->failure());
    }

    public function testATooManyRequestsAnswerIsNotAskedAgainOverTheNextFamily(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });

        $answer = $this->onlyAnswer($client, self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedTooManyRequestsAnswerIsNotAskedAgainDirectly(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null));

        $answer = self::collect($engine->sendAll([1 => self::ticket()]))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }
}
