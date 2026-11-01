<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryRepostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string REBLOG = 'POST https://mastodon.example/api/v1/statuses/110/reblog';
    private const string UNREBLOG = 'POST https://mastodon.example/api/v1/statuses/110/unreblog';
    private const string REPLY = 'https://hachyderm.io/@ann/200';
    private const string REPLY_SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fhachyderm.io%2F%40ann%2F200&resolve=true&type=statuses&limit=1';
    private const string REPLY_REBLOG = 'POST https://mastodon.example/api/v1/statuses/200/reblog';
    private const string REPLY_UNREBLOG = 'POST https://mastodon.example/api/v1/statuses/200/unreblog';

    /** Must match framework.rate_limiter.social_writes.limit in rate_limiter.yaml. */
    private const int WRITE_BUDGET = 30;

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

        $client->request('PUT', '/api/entries/1/repost');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testARepostBoostsThePostOnTheAccountsInstance(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"110"}]}'),
            self::REBLOG => new MockResponse('{"id":"220","reblogged":true}'),
        ]);
        $user = $this->signIn($client, 'repost-put@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/repost', $this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['isReposted' => true], $this->payload($client));
        self::assertSame([self::SEARCH, self::REBLOG], $this->routesAsked());
    }

    public function testAnUnrepostUndoesTheBoostTheInstanceReports(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"110","reblogged":true}]}'),
            self::UNREBLOG => new MockResponse('{"id":"110","reblogged":false}'),
        ]);
        $user = $this->signIn($client, 'repost-delete@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', sprintf('/api/entries/%d/repost', $this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['isReposted' => false], $this->payload($client));
        self::assertSame([self::SEARCH, self::UNREBLOG], $this->routesAsked());
    }

    public function testAPrivatePostIsRefusedWithoutABoost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"110","visibility":"private"}]}'),
        ]);
        $user = $this->signIn($client, 'repost-private@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/repost', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_post_refused', $this->payload($client)['type']);
        self::assertSame([self::SEARCH], $this->routesAsked());
    }

    public function testATokenWithoutTheStatusesScopeNamesIt(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => new MockResponse('{"statuses":[{"id":"110"}]}'),
            self::REBLOG => new MockResponse(
                '{"error":"This action is outside the authorized scopes"}',
                ['http_code' => Response::HTTP_FORBIDDEN],
            ),
        ]);
        $user = $this->signIn($client, 'repost-scope@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/repost', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        $problem = $this->payload($client);
        self::assertSame('social_token_scope_missing', $problem['type']);
        self::assertIsString($problem['detail']);
        self::assertStringContainsString('write:statuses', $problem['detail']);
    }

    public function testAReplyRepostBoostsTheReply(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::REPLY_SEARCH => new MockResponse('{"statuses":[{"id":"200"}]}'),
            self::REPLY_REBLOG => new MockResponse('{"id":"300","reblogged":true}'),
        ]);
        $user = $this->signIn($client, 'repost-reply@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', $this->replyPath($this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['isReposted' => true], $this->payload($client));
        self::assertSame([self::REPLY_SEARCH, self::REPLY_REBLOG], $this->routesAsked());
    }

    public function testAReplyUnrepostUndoesItsBoost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::REPLY_SEARCH => new MockResponse('{"statuses":[{"id":"200","reblogged":true}]}'),
            self::REPLY_UNREBLOG => new MockResponse('{"id":"200"}'),
        ]);
        $user = $this->signIn($client, 'unrepost-reply@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', $this->replyPath($this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame(['isReposted' => false], $this->payload($client));
        self::assertSame([self::REPLY_SEARCH, self::REPLY_UNREBLOG], $this->routesAsked());
    }

    public function testAReplyRepostWithoutAnAddressIsABadRequest(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'repost-reply-no-address@example.test');

        $client->request('PUT', sprintf('/api/entries/%d/comments/repost', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_BAD_REQUEST);
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('repost-stranger@example.test'));
        $user = $this->signIn($client, 'repost-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('PUT', sprintf('/api/entries/%d/repost', $entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    #[DataProvider('writes')]
    public function testEveryRepostActionDrawsOnTheWriteBudget(string $method, bool $reply): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('repost-budget-%s-%d@example.test', strtolower($method), (int) $reply));
        $entryId = $this->mastodonPostFor($user);
        for ($attempt = 0; $attempt < self::WRITE_BUDGET; ++$attempt) {
            $client->request('DELETE', sprintf('/api/entries/%d/repost', $entryId));
        }

        $client->request($method, $reply ? $this->replyPath($entryId) : sprintf('/api/entries/%d/repost', $entryId));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function writes(): iterable
    {
        yield 'repost' => ['PUT', false];
        yield 'unrepost' => ['DELETE', false];
        yield 'reply repost' => ['PUT', true];
        yield 'reply unrepost' => ['DELETE', true];
    }

    private function replyPath(int $entryId): string
    {
        return sprintf('/api/entries/%d/comments/repost?address=%s', $entryId, rawurlencode(self::REPLY));
    }
}
