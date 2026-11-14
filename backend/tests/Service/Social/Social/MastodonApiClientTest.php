<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
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
        $client = new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        try {
            $client->send(ApiRequestModel::get(self::HOME));
            self::fail('A 429 must not read as an answer.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(90, $exception->retryAfterSeconds);
            self::assertInstanceOf(SocialNetworkUnreachableException::class, $exception);
        }
    }
}
