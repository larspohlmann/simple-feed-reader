<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostReposter;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReposter\MastodonPostReposter;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostReposterTest extends TestCase
{
    private const string REBLOG = 'POST https://mastodon.example/api/v1/statuses/110/reblog';
    private const string UNREBLOG = 'POST https://mastodon.example/api/v1/statuses/110/unreblog';

    public function testARepostBoostsTheStatus(): void
    {
        $http = new RoutedHttpClient([self::REBLOG => new MockResponse('{"id":"220","reblogged":true}')]);

        $this->reposter($http)->repost(self::post());

        self::assertSame([self::REBLOG], array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $http->requests,
        ));
    }

    public function testAStatusGoneBeforeTheBoostIsNotFound(): void
    {
        $http = new RoutedHttpClient([
            self::REBLOG => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->reposter($http)->repost(self::post());
    }

    public function testAForbiddenBoostIsARefusalOfThePost(): void
    {
        $http = new RoutedHttpClient([
            self::REBLOG => new MockResponse('{}', ['http_code' => Response::HTTP_FORBIDDEN]),
        ]);

        $this->expectException(SocialPostRefusedException::class);

        $this->reposter($http)->repost(self::post());
    }

    public function testABoostTheTokenHasNoScopeForNamesTheStatusesScope(): void
    {
        $http = new RoutedHttpClient([
            self::REBLOG => new MockResponse(
                '{"error":"This action is outside the authorized scopes"}',
                ['http_code' => Response::HTTP_FORBIDDEN],
            ),
        ]);

        try {
            $this->reposter($http)->repost(self::post());
            self::fail('A token without the scope must not read as a boost.');
        } catch (SocialTokenScopeMissingException $exception) {
            self::assertSame('write:statuses', $exception->missingScope);
        }
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::REBLOG => new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->reposter($http)->repost(self::post());
    }

    public function testAThrottledBoostCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([self::REBLOG => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '60'],
        ])]);

        try {
            $this->reposter($http)->repost(self::post());
            self::fail('A 429 must not read as a boost.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testUndoingABoostUnreblogsTheStatus(): void
    {
        $http = new RoutedHttpClient([self::UNREBLOG => new MockResponse('{"id":"110","reblogged":false}')]);

        $this->reposter($http)->unrepost(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    public function testUndoingABoostOfAStatusThatIsGoneSucceeds(): void
    {
        $http = new RoutedHttpClient([
            self::UNREBLOG => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        $this->reposter($http)->unrepost(SocialAccounts::mastodon(), '110');

        self::assertCount(1, $http->requests);
    }

    private function reposter(RoutedHttpClient $http): MastodonPostReposter
    {
        return new MastodonPostReposter(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110');
    }
}
