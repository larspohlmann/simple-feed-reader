<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Enum\ProxyType;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedApiClientTest extends TestCase
{
    private function client(MockHttpClient $http, ?ProxyConfigModel $proxy = null): GuardedApiClient
    {
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'api.example.test' => ['93.184.216.34'],
                'lan.example.test' => ['192.168.1.5'],
                'dual.example.test' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'],
            ]),
            new IpValidator(),
        );

        return FetchWiring::guardedApiClient($http, $guard, $proxy);
    }

    public function testAPostSendsJsonPinnedToTheGuardedAddressWithoutFollowingRedirects(): void
    {
        $seenMethod = null;
        $seenUrl = null;
        $seenOptions = [];
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seenMethod, &$seenUrl, &$seenOptions) {
                $seenMethod = $method;
                $seenUrl = $url;
                $seenOptions = $options;

                return new MockResponse('{"ok":true}', ['http_code' => Response::HTTP_OK]);
            },
        );

        $response = $this->client($http)->send(
            ApiRequestModel::postJson('https://api.example.test/x', ['a' => 1], ['Authorization' => 'Bearer t']),
        );

        self::assertSame('POST', $seenMethod);
        self::assertSame('https://api.example.test/x', $seenUrl);
        self::assertSame('{"a":1}', $seenOptions['body']);
        self::assertSame(0, $seenOptions['max_redirects']);
        self::assertSame(['api.example.test' => '93.184.216.34'], $seenOptions['resolve']);
        self::assertIsArray($seenOptions['headers']);
        self::assertContains('Authorization: Bearer t', $seenOptions['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $seenOptions['headers']);
        self::assertTrue($response->isSuccessful());
        self::assertSame(['ok' => true], $response->json());
    }

    public function testTheCallCarriesItsTimeoutsJsonAcceptHeaderAndNoFreshConnectionExtra(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{}');
        });

        $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(10.0, $seenOptions['timeout']);
        self::assertSame(20.0, $seenOptions['max_duration']);
        self::assertIsArray($seenOptions['headers']);
        self::assertContains('Accept: application/json', $seenOptions['headers']);
        self::assertArrayNotHasKey('extra', $seenOptions);
        self::assertArrayNotHasKey('json', $seenOptions);
    }

    public function testACallWithinABudgetCarriesItsTimeoutsAndItsSizeLimit(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse(str_repeat('a', ResponseSizeLimit::ChapterFile->bytes() + 1));
        });
        $budget = new ApiBudgetModel(1.5, 2.5, ResponseSizeLimit::ChapterFile);

        try {
            $this->client($http)->sendWithin(ApiRequestModel::get('https://api.example.test/x'), $budget);
            self::fail('expected the answer to be refused');
        } catch (ResponseTooLargeException) {
            self::assertSame(1.5, $seenOptions['timeout']);
            self::assertSame(2.5, $seenOptions['max_duration']);
            self::assertSame(0, $seenOptions['max_redirects']);
        }
    }

    public function testTheBodyIsRequestedUncompressedSoTheWireLimitBoundsIt(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{}');
        });

        $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertIsArray($seenOptions['headers']);
        self::assertContains('Accept-Encoding: identity', $seenOptions['headers']);
    }

    public function testAConfiguredEgressProxyCarriesTheCallInsteadOfAnAddressPin(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{}');
        });
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.test', 1080, null, null, true);

        $this->client($http, $proxy)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame('socks5://proxy.example.test:1080', $seenOptions['proxy'] ?? null);
        self::assertArrayNotHasKey('resolve', $seenOptions);
    }

    public function testAnAnswerBeyondTheApiSizeLimitIsRefused(): void
    {
        $oversize = new MockResponse(str_repeat('a', ResponseSizeLimit::Api->bytes() + 1));
        $http = new MockHttpClient($oversize);

        $this->expectException(ResponseTooLargeException::class);

        $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));
    }

    public function testATransportFailureBecomesAFetchException(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'connection reset']));

        $this->expectException(FetchException::class);

        $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));
    }

    public function testAnErrorStatusIsReturnedNotThrown(): void
    {
        $http = new MockHttpClient(new MockResponse(
            '{"error":"AuthenticationRequired"}',
            ['http_code' => Response::HTTP_UNAUTHORIZED],
        ));

        $response = $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertSame(['error' => 'AuthenticationRequired'], $response->json());
    }

    public function testAPrivateTargetIsRefusedBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('No request may leave for a private address.'));

        $this->expectException(SsrfBlockedException::class);

        $this->client($http)->send(ApiRequestModel::get('https://lan.example.test/x'));
    }

    public function testANonJsonBodyReadsAsEmpty(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>', ['http_code' => Response::HTTP_BAD_GATEWAY]));

        self::assertSame([], $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'))->json());
    }

    public function testTheAnswerCarriesItsResponseHeaders(): void
    {
        $http = new MockHttpClient(new MockResponse('[]', [
            'http_code' => Response::HTTP_OK,
            'response_headers' => ['Link' => '<https://api.example.test/x?page=2>; rel="next"'],
        ]));

        $response = $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame('<https://api.example.test/x?page=2>; rel="next"', $response->header('link'));
    }

    public function testATooManyRequestsAnswerCostsOneRequestOverEveryFamily(): void
    {
        $http = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        );

        $response = $this->client($http)->send(ApiRequestModel::get('https://dual.example.test/x'));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->status);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testAProxiedTooManyRequestsAnswerCostsOneRequest(): void
    {
        $http = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]),
        );
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null);

        $response = $this->client($http, $proxy)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->status);
        self::assertSame(1, $http->getRequestsCount());
    }
}
