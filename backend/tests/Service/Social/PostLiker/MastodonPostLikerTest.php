<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostLiker;

use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\MastodonPostLiker;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostLikerTest extends TestCase
{
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/favourite';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/110/unfavourite';

    public function testALikeFavouritesTheStatusAndKeepsItsIdToUndoIt(): void
    {
        $http = new RoutedHttpClient([self::FAVOURITE => new MockResponse('{"id":"110","favourited":true}')]);

        $result = $this->liker($http)->like(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110'));

        self::assertSame('110', $result->likeId);
        self::assertSame([self::FAVOURITE], array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $http->requests,
        ));
    }

    public function testAStatusGoneBeforeTheLikeIsNotFound(): void
    {
        $http = new RoutedHttpClient([
            self::FAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->liker($http)->like(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110'));
    }

    public function testAThrottledLikeCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([self::FAVOURITE => self::throttled()]);

        try {
            $this->liker($http)->like(SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110'));
            self::fail('A 429 must not read as a like.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testUnlikingUnfavouritesTheStatus(): void
    {
        $http = new RoutedHttpClient([self::UNFAVOURITE => new MockResponse('{"id":"110","favourited":false}')]);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    public function testUnlikingAStatusThatIsGoneSucceeds(): void
    {
        $http = new RoutedHttpClient([
            self::UNFAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    public function testAThrottledUnlikeCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([self::UNFAVOURITE => self::throttled()]);

        try {
            $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');
            self::fail('A 429 must not read as taken back.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testAnUnlikeTheInstanceFailsIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::UNFAVOURITE => new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->unlike(SocialAccounts::mastodon(), '110');
    }

    private function liker(RoutedHttpClient $http): MastodonPostLiker
    {
        return new MastodonPostLiker(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    private static function throttled(): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '60'],
        ]);
    }
}
