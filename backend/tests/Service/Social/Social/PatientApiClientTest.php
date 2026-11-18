<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\PatientApiClient;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class PatientApiClientTest extends TestCase
{
    private const string SITE = 'https://lemmy.example/api/v3/site';

    private int $requests = 0;
    /** @var list<int> */
    private array $requestOffsets = [];
    private MockClock $clock;
    private int $startedAt;

    public function testAGetThatGets503IsSentAgainAfterOneSecond(): void
    {
        $client = $this->clientAnswering($this->unavailable(), new MockResponse('{}'));

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame(2, $this->requests);
        self::assertSame(1, $this->secondsSlept());
    }

    public function testAGetGivesUpAfterThreeAttempts(): void
    {
        $client = $this->clientAnswering($this->unavailable(), $this->unavailable(), $this->unavailable());

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $answer->status);
        self::assertSame(3, $this->requests);
        self::assertSame(3, $this->secondsSlept());
    }

    public function testAPostThatGets503IsSentOnce(): void
    {
        $client = $this->clientAnswering($this->unavailable(), new MockResponse('{}'));

        $answer = $client->send(ApiRequestModel::postJson(self::SITE, ['a' => 'b']));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $answer->status);
        self::assertSame(1, $this->requests);
        self::assertSame(0, $this->secondsSlept());
    }

    public function testRetryAfterWithinThreeSecondsSetsThePause(): void
    {
        $client = $this->clientAnswering($this->unavailable('3'), new MockResponse('{}'));

        $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(2, $this->requests);
        self::assertSame(3, $this->secondsSlept());
    }

    public function testALongRetryAfterEndsTheRetries(): void
    {
        $client = $this->clientAnswering($this->unavailable('120'), new MockResponse('{}'));

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $answer->status);
        self::assertSame(1, $this->requests);
        self::assertSame(0, $this->secondsSlept());
    }

    public function testARetryAfterDateTwoHoursAheadEndsTheRetries(): void
    {
        $client = $this->clientAnswering($this->unavailable('Wed, 18 Nov 2026 16:52:21 GMT'), new MockResponse('{}'));

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $answer->status);
        self::assertSame(1, $this->requests);
        self::assertSame(0, $this->secondsSlept());
    }

    public function testARetryAfterDateWithinThreeSecondsSetsThePause(): void
    {
        $client = $this->clientAnswering($this->unavailable('Wed, 18 Nov 2026 14:52:24 GMT'), new MockResponse('{}'));

        $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(2, $this->requests);
        self::assertSame(3, $this->secondsSlept());
    }

    public function testThePausesGrowFromOneSecondToTwo(): void
    {
        $client = $this->clientAnswering($this->unavailable(), $this->unavailable(), new MockResponse('{}'));

        $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame([0, 1, 3], $this->requestOffsets);
        self::assertSame(3, $this->secondsSlept());
    }

    public function testEachOutageStatusIsRetried(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            new MockResponse('', ['http_code' => Response::HTTP_GATEWAY_TIMEOUT]),
            new MockResponse('{}'),
        );

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame(3, $this->requests);
    }

    public function testRetryAfterOfFourSecondsEndsTheRetries(): void
    {
        $client = $this->clientAnswering($this->unavailable('4'), new MockResponse('{}'));

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $answer->status);
        self::assertSame(1, $this->requests);
        self::assertSame(0, $this->secondsSlept());
    }

    public function testASecondPauseFallsBackToTwoSecondsWithoutRetryAfter(): void
    {
        $client = $this->clientAnswering($this->unavailable('2'), $this->unavailable(), new MockResponse('{}'));

        $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame([0, 2, 4], $this->requestOffsets);
    }

    public function testA500IsNotRetried(): void
    {
        $client = $this->clientAnswering(
            new MockResponse('', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
            new MockResponse('{}'),
        );

        $answer = $client->send(ApiRequestModel::get(self::SITE));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $answer->status);
        self::assertSame(1, $this->requests);
    }

    private function unavailable(?string $retryAfter = null): MockResponse
    {
        $headers = null === $retryAfter ? [] : ['response_headers' => ['Retry-After: ' . $retryAfter]];

        return new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE] + $headers);
    }

    private function secondsSlept(): int
    {
        return $this->clock->now()->getTimestamp() - $this->startedAt;
    }

    private function clientAnswering(MockResponse ...$responses): PatientApiClient
    {
        $this->clock = new MockClock('2026-11-18 14:52:21', 'UTC');
        $this->startedAt = $this->clock->now()->getTimestamp();
        $queue = $responses;
        $http = new MockHttpClient(function () use (&$queue): MockResponse {
            $this->requests++;
            $this->requestOffsets[] = $this->secondsSlept();

            return array_shift($queue) ?? new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]);
        });
        $guard = new UrlGuard(new StaticDnsResolver(['lemmy.example' => ['93.184.216.34']]), new IpValidator());

        return new PatientApiClient(FetchWiring::guardedApiClient($http, $guard), $this->clock);
    }
}
