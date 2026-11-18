<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialWriteOutcomeUnknownException;
use App\Service\Social\Social\MastodonApiClient;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonApiClientTest extends TestCase
{
    private const string HOME = 'https://mastodon.example/api/v1/timelines/home';

    public function testTooManyRequestsIsAThrottleCarryingItsDelay(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::HOME => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '90'],
        ])]);
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());
        $client = new MastodonApiClient(FetchWiring::patientApiClient($http->client(), $guard));

        try {
            $client->send(ApiRequestModel::get(self::HOME));
            self::fail('A 429 must not read as an answer.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(90, $exception->retryAfterSeconds);
            self::assertInstanceOf(SocialNetworkUnreachableException::class, $exception);
        }
    }

    public function testAGetThatKeepsGetting503NamesTheInstance(): void
    {
        $http = $this->alwaysAnswering(Response::HTTP_SERVICE_UNAVAILABLE);

        try {
            $this->clientOver($http)->send(ApiRequestModel::get(self::HOME));
            self::fail('A 503 must not read as an answer.');
        } catch (SocialNetworkUnavailableException $exception) {
            self::assertSame(
                "mastodon.example isn't responding right now — it's probably overloaded. Try again in a minute.",
                $exception->getMessage(),
            );
            self::assertInstanceOf(SocialNetworkUnreachableException::class, $exception);
            self::assertStringNotContainsString('503', $exception->getMessage());
            self::assertCount(3, $http->requests);
        }
    }

    public function testAPostThatGets502MayHaveGoneThrough(): void
    {
        $http = $this->alwaysAnswering(Response::HTTP_BAD_GATEWAY);

        try {
            $this->clientOver($http)->send(ApiRequestModel::post('https://mastodon.example/api/v1/statuses'));
            self::fail('A 502 must not read as an answer.');
        } catch (SocialWriteOutcomeUnknownException $exception) {
            self::assertSame(
                'mastodon.example had trouble with this request, so it may or may not have gone through. '
                . 'Check before trying again.',
                $exception->getMessage(),
            );
            self::assertCount(1, $http->requests);
        }
    }

    public function testAnyOtherFailureIsAProblemOfTheInstance(): void
    {
        $client = $this->clientOver($this->alwaysAnswering(Response::HTTP_NOT_FOUND));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('The Mastodon instance ran into a problem of its own.');

        $client->send(ApiRequestModel::get(self::HOME));
    }

    public function testAnAnswerThatGets502OnAWriteMayHaveGoneThroughWhateverTheCaller(): void
    {
        $client = $this->clientOver($this->alwaysAnswering(Response::HTTP_BAD_GATEWAY));

        $this->expectException(SocialWriteOutcomeUnknownException::class);
        $this->expectExceptionMessage('mastodon.example had trouble with this request');

        $client->answer(ApiRequestModel::postJson('https://mastodon.example/api/v1/statuses', ['status' => 'Hi']));
    }

    private function alwaysAnswering(int $status): RoutedHttpClient
    {
        return RoutedHttpClient::answering(
            static fn (): MockResponse => new MockResponse('', ['http_code' => $status]),
        );
    }

    private function clientOver(RoutedHttpClient $http): MastodonApiClient
    {
        $guard = new UrlGuard(new StaticDnsResolver(['mastodon.example' => ['93.184.216.34']]), new IpValidator());

        return new MastodonApiClient(
            FetchWiring::patientApiClient($http->client(), $guard),
        );
    }
}
