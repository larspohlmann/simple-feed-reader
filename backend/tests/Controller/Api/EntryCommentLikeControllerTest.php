<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryCommentLikeControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string REPLY = 'https://hachyderm.io/@ann/200';
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fhachyderm.io%2F%40ann%2F200&resolve=true&type=statuses&limit=1';
    private const string FAVOURITE = 'POST https://mastodon.example/api/v1/statuses/200/favourite';
    private const string UNFAVOURITE = 'POST https://mastodon.example/api/v1/statuses/200/unfavourite';

    /** Must match framework.rate_limiter.social_like.limit in rate_limiter.yaml. */
    private const int LIKE_BUDGET = 60;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $client = static::createClient();

        $client->request('PUT', '/api/entries/1/comments/like?address=' . rawurlencode(self::REPLY));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testALikeFavouritesTheReplyOnTheAccountsInstance(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"200"}]}'),
            self::FAVOURITE => new MockResponse('{"id":"200"}'),
        ]);
        $user = $this->signIn($client, 'comment-like@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', $this->likePath($this->mastodonPostFor($user), self::REPLY));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertSame([self::SEARCH, self::FAVOURITE], $this->routesAsked());
    }

    public function testAnUnlikeUnfavouritesAReplyTheAccountFavourited(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"200","favourited":true}]}'),
            self::UNFAVOURITE => new MockResponse('{"id":"200"}'),
        ]);
        $user = $this->signIn($client, 'comment-unlike@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', $this->likePath($this->mastodonPostFor($user), self::REPLY));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame([self::SEARCH, self::UNFAVOURITE], $this->routesAsked());
    }

    public function testAnAddressOffTheEntrysNetworkIsNoPostToLike(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'comment-like-off-network@example.test');
        $this->mastodonAccountOf($user);

        $blueskyPost = 'at://did:plc:ann/app.bsky.feed.post/1';

        $client->request('PUT', $this->likePath($this->mastodonPostFor($user), $blueskyPost));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_unsupported', $this->payload($client)['type']);
        self::assertSame([], $this->http->requests);
    }

    public function testALikeWithoutAnAddressIsABadRequest(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'comment-like-no-address@example.test');

        $client->request('PUT', sprintf('/api/entries/%d/comments/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('comment-like-stranger@example.test'));
        $user = $this->signIn($client, 'comment-like-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', $this->likePath($entryId, self::REPLY));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    #[DataProvider('likeMethods')]
    public function testCommentLikesDrawOnTheLikeBudget(string $method): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('comment-like-budget-%s@example.test', strtolower($method)));
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);
        for ($attempt = 0; $attempt < self::LIKE_BUDGET; ++$attempt) {
            $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));
        }

        $client->request($method, $this->likePath($entryId, self::REPLY));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return iterable<string, array{string}> */
    public static function likeMethods(): iterable
    {
        yield 'like' => ['PUT'];
        yield 'unlike' => ['DELETE'];
    }

    private function likePath(int $entryId, string $address): string
    {
        return sprintf('/api/entries/%d/comments/like?address=%s', $entryId, rawurlencode($address));
    }
}
