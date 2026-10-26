<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter;

use App\Enum\ProxyType;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Exception\DirectRouteUnavailableException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class GuardedDescriptionRequestTest extends TestCase
{
    private const string URL = 'https://cdn.test/answer.json';

    public function testAnAnsweredRequestGivesTheDecodedAnswer(): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(
            new MockResponse('{"description":"0:00 Intro"}', ['http_code' => Response::HTTP_OK]),
        )));

        $answer = $request->answerOf(ApiRequestModel::get(self::URL), $this->embed());

        self::assertSame(['description' => '0:00 Intro'], $answer);
    }

    #[DataProvider('answersWithoutADescription')]
    public function testAnErrorAnswerOrARedirectGivesNothing(int $status): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(new MockResponse('', [
            'http_code' => $status,
            'response_headers' => ['Location' => 'https://elsewhere.test/answer.json'],
        ]))));

        self::assertSame([], $request->answerOf(ApiRequestModel::get(self::URL), $this->embed()));
    }

    /** @return iterable<string, array{int}> */
    public static function answersWithoutADescription(): iterable
    {
        yield 'not found' => [Response::HTTP_NOT_FOUND];
        yield 'last client error' => [Response::HTTP_INTERNAL_SERVER_ERROR - 1];
        yield 'redirect' => [Response::HTTP_MOVED_PERMANENTLY];
    }

    #[DataProvider('transientAnswers')]
    public function testATransientAnswerIsUnreachable(int $status): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(
            new MockResponse('', ['http_code' => $status]),
        )));

        $this->expectException(EmbedDescriptionUnreachableException::class);
        $request->answerOf(ApiRequestModel::get(self::URL), $this->embed());
    }

    /** @return iterable<string, array{int}> */
    public static function transientAnswers(): iterable
    {
        yield 'request timeout' => [Response::HTTP_REQUEST_TIMEOUT];
        yield 'rate limited' => [Response::HTTP_TOO_MANY_REQUESTS];
        yield 'internal error' => [Response::HTTP_INTERNAL_SERVER_ERROR];
        yield 'unavailable' => [Response::HTTP_SERVICE_UNAVAILABLE];
    }

    public function testTheRequestCarriesTheShortBudget(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{}');
        });

        $request = new GuardedDescriptionRequest($this->client($http));
        $request->answerOf(ApiRequestModel::get(self::URL), $this->embed());

        self::assertSame(3.0, $seenOptions['timeout']);
        self::assertSame(3.0, $seenOptions['max_duration']);
        self::assertSame(0, $seenOptions['max_redirects']);
    }

    public function testAnAnswerAtTheSizeLimitIsRead(): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(
            new MockResponse(self::answerPaddedTo(ResponseSizeLimit::EmbedDescription->bytes())),
        )));

        self::assertSame(['description' => 'x'], $request->answerOf(ApiRequestModel::get(self::URL), $this->embed()));
    }

    public function testAnAnswerBeyondTheSizeLimitGivesNothing(): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(
            new MockResponse(self::answerPaddedTo(ResponseSizeLimit::EmbedDescription->bytes() + 1)),
        )));

        self::assertSame([], $request->answerOf(ApiRequestModel::get(self::URL), $this->embed()));
    }

    private static function answerPaddedTo(int $bytes): string
    {
        return str_pad('{"description":"x"}', $bytes, ' ');
    }

    public function testABlockedHostGivesNothing(): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient()));

        $answer = $request->answerOf(ApiRequestModel::get('http://127.0.0.1/answer.json'), $this->embed());

        self::assertSame([], $answer);
    }

    public function testANetworkFailureIsUnreachable(): void
    {
        $request = new GuardedDescriptionRequest($this->client(new MockHttpClient(
            new MockResponse('', ['error' => 'Connection reset']),
        )));

        $this->expectException(EmbedDescriptionUnreachableException::class);
        $request->answerOf(ApiRequestModel::get(self::URL), $this->embed());
    }

    public function testADirectAnswerIsAskedWithinTheShortBudgetOffTheProxy(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{"description":"0:00 Intro"}');
        });
        $request = new GuardedDescriptionRequest($this->client($http, $this->proxy(directFallback: true)));

        $answer = $request->directAnswerOf(ApiRequestModel::get(self::URL), $this->embed());

        self::assertSame(['description' => '0:00 Intro'], $answer);
        self::assertArrayNotHasKey('proxy', $seenOptions);
        self::assertSame(3.0, $seenOptions['timeout'] ?? null);
        self::assertSame(3.0, $seenOptions['max_duration'] ?? null);
    }

    public function testADirectAnswerThatFailsIsUnreachable(): void
    {
        $request = new GuardedDescriptionRequest($this->client(
            new MockHttpClient(new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])),
            $this->proxy(directFallback: true),
        ));

        $this->expectException(EmbedDescriptionUnreachableException::class);
        $request->directAnswerOf(ApiRequestModel::get(self::URL), $this->embed());
    }

    public function testNoDirectAnswerIsAskedWhenTheProxyForbidsIt(): void
    {
        $request = new GuardedDescriptionRequest($this->client(
            new MockHttpClient(static fn () => self::fail('No request may leave without the proxy.')),
            $this->proxy(directFallback: false),
        ));

        $this->expectException(DirectRouteUnavailableException::class);
        $request->directAnswerOf(ApiRequestModel::get(self::URL), $this->embed());
    }

    private function proxy(bool $directFallback): ProxyConfigModel
    {
        return new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.test', 1080, null, null, $directFallback);
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'dQw4w9WgXcQ');
    }

    private function client(MockHttpClient $http, ?ProxyConfigModel $proxy = null): GuardedApiClient
    {
        $guard = new UrlGuard(new StaticDnsResolver(['cdn.test' => ['93.184.216.34']]), new IpValidator());

        return FetchWiring::guardedApiClient($http, $guard, $proxy);
    }
}
