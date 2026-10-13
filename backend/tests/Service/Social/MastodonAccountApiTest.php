<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonAccountApiTest extends TestCase
{
    private const string STATUS = 'https://mastodon.example/api/v1/statuses/110';

    public function testAGetCarriesTheAccountsTokenToItsOwnInstance(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::STATUS => new MockResponse('{"id":"110"}')]);

        $answer = SocialApiWiring::mastodonAccountApi($http->client())
            ->get(SocialAccounts::mastodon(), '/api/v1/statuses/110');

        self::assertSame('{"id":"110"}', $answer->body);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAPostCarriesTheTokenAndNoBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::STATUS . '/favourite' => new MockResponse('{}')]);

        SocialApiWiring::mastodonAccountApi($http->client())
            ->post(SocialAccounts::mastodon(), '/api/v1/statuses/110/favourite');

        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
        self::assertSame('', $http->requests[0]['body']);
    }

    public function testEveryStatusComesBackForTheCallerToRead(): void
    {
        $http = new RoutedHttpClient([
            'POST ' . self::STATUS . '/unfavourite' => new MockResponse('{}', [
                'http_code' => Response::HTTP_NOT_FOUND,
            ]),
        ]);

        $answer = SocialApiWiring::mastodonAccountApi($http->client())
            ->post(SocialAccounts::mastodon(), '/api/v1/statuses/110/unfavourite');

        self::assertSame(Response::HTTP_NOT_FOUND, $answer->status);
    }

    #[DataProvider('refusals')]
    public function testARefusedTokenIsRefusedOnGetAndOnPost(string $method, int $status): void
    {
        $http = new RoutedHttpClient([
            $method . ' ' . self::STATUS => new MockResponse('{}', ['http_code' => $status]),
        ]);
        $api = SocialApiWiring::mastodonAccountApi($http->client());
        $answer = 'GET' === $method
            ? $api->get(SocialAccounts::mastodon(), '/api/v1/statuses/110')
            : $api->post(SocialAccounts::mastodon(), '/api/v1/statuses/110');

        $this->expectException(SocialCredentialsRejectedException::class);

        MastodonApiClient::accepted($answer);
    }

    /** @return iterable<string, array{string, int}> */
    public static function refusals(): iterable
    {
        yield 'get unauthorised' => ['GET', Response::HTTP_UNAUTHORIZED];
        yield 'get forbidden' => ['GET', Response::HTTP_FORBIDDEN];
        yield 'post unauthorised' => ['POST', Response::HTTP_UNAUTHORIZED];
        yield 'post forbidden' => ['POST', Response::HTTP_FORBIDDEN];
    }

    public function testNoAnswerAtAllIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::STATUS => new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        SocialApiWiring::mastodonAccountApi($http->client())->get(SocialAccounts::mastodon(), '/api/v1/statuses/110');
    }
}
