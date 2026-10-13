<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostResolver;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\PostResolver\MastodonPostResolver;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonPostResolverTest extends TestCase
{
    private const string OUTSIDE_SCOPES = 'This action is outside the authorized scopes';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';

    public function testAPostResolvesToItsStatusIdOnTheAccountsInstance(): void
    {
        $account = SocialAccounts::mastodon();
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => '109876']])]);

        $post = $this->resolver($http)->resolve($account, self::STATUS_URL);

        self::assertSame($account, $post->account);
        self::assertSame('109876', $post->postId);
        self::assertNull($post->contentId);
        self::assertNull($post->existingLikeId);
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testAnAddressCannotInjectSearchParameters(): void
    {
        $address = 'https://mastodon.social/@a/1&resolve=false#x';
        $expected = 'GET https://mastodon.example/api/v2/search?q='
            . 'https%3A%2F%2Fmastodon.social%2F%40a%2F1%26resolve%3Dfalse%23x&resolve=true&type=statuses&limit=1';
        $http = new RoutedHttpClient([$expected => self::statuses([['id' => '5']])]);

        self::assertSame('5', $this->resolver($http)->resolve(SocialAccounts::mastodon(), $address)->postId);
    }

    public function testAPostTheInstanceCannotFindIsNotFound(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([])]);

        $this->expectException(SocialPostNotFoundException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    #[DataProvider('foreignStatusIds')]
    public function testAStatusIdThatCouldLeaveItsPathIsAnUnreachableNetwork(string $statusId): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => $statusId]])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignStatusIds(): iterable
    {
        yield 'parent path' => ['../1'];
        yield 'slash' => ['1/favourite'];
        yield 'query' => ['1?x=2'];
        yield 'too long' => [str_repeat('1', 65)];
    }

    public function testAFlakeIdOfAnotherServerIsKept(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::statuses([['id' => 'AbC123xyz']])]);

        $post = $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);

        self::assertSame('AbC123xyz', $post->postId);
    }

    public function testARefusedTokenIsARefusedCredential(): void
    {
        $refusal = new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
        $http = new RoutedHttpClient([self::SEARCH => $refusal]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    public function testATokenWithoutTheScopeIsAMissingPermissionNotARefusedCredential(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::forbidden(self::OUTSIDE_SCOPES)]);

        $this->expectException(SocialTokenScopeMissingException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    public function testAnyOtherForbiddenSearchIsARefusalOfThePostNotOfTheToken(): void
    {
        $http = new RoutedHttpClient([self::SEARCH => self::forbidden('Your login is currently disabled')]);

        $this->expectException(SocialPostRefusedException::class);

        $this->resolver($http)->resolve(SocialAccounts::mastodon(), self::STATUS_URL);
    }

    private function resolver(RoutedHttpClient $http): MastodonPostResolver
    {
        return new MastodonPostResolver(SocialApiWiring::mastodonAccountApi($http->client()));
    }

    private static function forbidden(string $error): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => $error], \JSON_THROW_ON_ERROR),
            ['http_code' => Response::HTTP_FORBIDDEN],
        );
    }

    /** @param list<array<string, string>> $statuses */
    private static function statuses(array $statuses): MockResponse
    {
        return new MockResponse(json_encode(
            ['accounts' => [], 'statuses' => $statuses, 'hashtags' => []],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
