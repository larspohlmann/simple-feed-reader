<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntrySocialPostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string REPLY = 'https://mastodon.example/users/alice/statuses/200';
    private const string REPLY_SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.example%2Fusers%2Falice%2Fstatuses%2F200&resolve=true&type=statuses&limit=1';
    private const string QUOTE = 'https://mastodon.example/users/alice/statuses/201';
    private const string DELETE_REPLY = 'DELETE https://mastodon.example/api/v1/statuses/200';
    private const string IDEMPOTENCY_KEY = '9b2f6c1e-4a7d-4c1b-8f3e-2d5a6b7c8d9e';

    /** Must match framework.rate_limiter.social_writes.limit in rate_limiter.yaml. */
    private const int WRITE_BUDGET = 30;

    /** Must match framework.rate_limiter.social_viewer.limit in rate_limiter.yaml. */
    private const int VIEWER_BUDGET = 120;

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

        $client->request('POST', '/api/entries/1/social/replies');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheComposerStartsFromTheInstancesLimitTheStricterVisibilityAndTheAuthor(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses([
                'id' => '110',
                'visibility' => 'public',
                'account' => [
                    'display_name' => 'Eugen',
                    'acct' => 'Gargron@mastodon.social',
                    'url' => 'https://mastodon.social/@Gargron',
                ],
                'content' => '<p>Hello <b>world</b></p>',
                'card' => ['url' => 'https://example.org/story', 'title' => 'The story', 'provider_name' => 'Example'],
            ]),
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":1000}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"unlisted"}}'),
        ]);
        $user = $this->signIn($client, 'composer-context@example.test');
        $account = $this->mastodonAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d/social/composer', $this->mastodonPostFor($user)));

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => '@alice@mastodon.example']],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => 1000,
            'visibility' => 'unlisted',
            'kind' => 'reply',
            'network' => 'mastodon',
            'text' => '@gargron@mastodon.social ',
            'target' => [
                'kind' => 'post',
                'authorName' => 'Eugen',
                'authorHandle' => '@Gargron@mastodon.social',
                'excerpt' => 'Hello world',
                'card' => [
                    'url' => 'https://example.org/story',
                    'title' => 'The story',
                    'description' => null,
                    'imageUrl' => null,
                    'siteName' => 'Example',
                ],
            ],
        ], $this->payload($client));
    }

    public function testTheComposerForAQuoteMentionsNobodyAndAnUnknownKindIsABadRequest(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110', 'quote_approval' => ['current_user' => 'manual']]),
            self::INSTANCE => new MockResponse('{}'),
            self::OWN_ACCOUNT => new MockResponse('{}'),
        ]);
        $user = $this->signIn($client, 'composer-quote@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', sprintf('/api/entries/%d/social/composer?kind=quote', $entryId));
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['canSend']);
        self::assertSame('quote', $payload['kind']);
        self::assertSame('', $payload['text']);

        $client->request('GET', sprintf('/api/entries/%d/social/composer?kind=boost', $entryId));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAReplyIsPublishedOnceWithTheComposersKey(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110']),
            self::STATUSES => new MockResponse(json_encode(
                ['id' => '200', 'uri' => self::REPLY, 'url' => 'https://mastodon.example/@alice/200'],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
        $user = $this->signIn($client, 'reply-post@example.test');
        $this->mastodonAccountOf($user);

        $this->postReply($client, $this->mastodonPostFor($user), [
            'text' => '@gargron@mastodon.social Thanks',
            'visibility' => 'unlisted',
            'language' => 'de',
            'idempotencyKey' => self::IDEMPOTENCY_KEY,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => self::REPLY, 'url' => 'https://mastodon.example/@alice/200'],
            $this->payload($client),
        );
        self::assertSame([self::SEARCH, self::STATUSES], $this->routesAsked());
        self::assertSame([
            'status' => '@gargron@mastodon.social Thanks',
            'in_reply_to_id' => '110',
            'visibility' => 'unlisted',
            'language' => 'de',
        ], json_decode($this->http->requests[1]['body'], true));
        self::assertContains('Idempotency-Key: ' . self::IDEMPOTENCY_KEY, $this->http->requests[1]['headers']);
    }

    /** @param array<string, string> $body */
    #[DataProvider('invalidReplies')]
    public function testAnInvalidReplyIsRejectedBeforeAnyCall(array $body): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'reply-invalid-' . md5((string) json_encode($body)) . '@example.test');
        $this->mastodonAccountOf($user);

        $this->postReply($client, $this->mastodonPostFor($user), $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidReplies(): iterable
    {
        yield 'blank text' => [['text' => '   ']];
        yield 'unknown visibility' => [['text' => 'Hi', 'visibility' => 'friends']];
        yield 'no language code' => [['text' => 'Hi', 'language' => 'German']];
        yield 'no uuid key' => [['text' => 'Hi', 'idempotencyKey' => 'again']];
    }

    public function testAReplyTheUsersAccountWroteIsDeleted(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::REPLY_SEARCH => self::statuses(['id' => '200', 'account' => [
                'acct' => 'alice',
                'url' => 'https://mastodon.example/@alice',
            ]]),
            self::DELETE_REPLY => new MockResponse('{}'),
        ]);
        $user = $this->signIn($client, 'reply-delete@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', $this->deletePath($this->mastodonPostFor($user)));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([self::REPLY_SEARCH, self::DELETE_REPLY], $this->routesAsked());
    }

    public function testAReplySomeoneElseWroteIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::REPLY_SEARCH => self::statuses(['id' => '200', 'account' => [
                'acct' => 'ann@hachyderm.io',
                'url' => 'https://hachyderm.io/@ann',
            ]]),
        ]);
        $user = $this->signIn($client, 'reply-delete-foreign@example.test');
        $this->mastodonAccountOf($user);

        $client->request('DELETE', $this->deletePath($this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame([self::REPLY_SEARCH], $this->routesAsked());
    }

    public function testAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('reply-stranger@example.test'));
        $user = $this->signIn($client, 'reply-own@example.test');
        $this->mastodonAccountOf($user);

        $this->postReply($client, $entryId, ['text' => 'Hi']);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testAQuoteQuotesThePostWithTheComposersChoices(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110', 'quote_approval' => ['current_user' => 'automatic']]),
            self::STATUSES => new MockResponse(json_encode(
                ['id' => '201', 'uri' => self::QUOTE, 'url' => 'https://mastodon.example/@alice/201'],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
        $user = $this->signIn($client, 'quote-post@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, 'POST', $this->quotePath($this->mastodonPostFor($user)), [
            'text' => 'Worth reading',
            'idempotencyKey' => self::IDEMPOTENCY_KEY,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => self::QUOTE, 'url' => 'https://mastodon.example/@alice/201'],
            $this->payload($client),
        );
        self::assertSame(
            ['status' => 'Worth reading', 'quoted_status_id' => '110'],
            json_decode($this->http->requests[1]['body'], true),
        );
        self::assertContains('Idempotency-Key: ' . self::IDEMPOTENCY_KEY, $this->http->requests[1]['headers']);
    }

    public function testAPostOnAnInstanceBeforeQuotesIsRefusedWithoutAWrite(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::SEARCH => self::statuses(['id' => '110'])]);
        $user = $this->signIn($client, 'quote-refused@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, 'POST', $this->quotePath($this->mastodonPostFor($user)), ['text' => 'Hi']);

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame([self::SEARCH], $this->routesAsked());
    }

    #[DataProvider('writes')]
    public function testRepliesQuotesAndDeletesDrawOnTheWriteBudgetRepostsUse(string $method, string $path): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('reply-budget-%s@example.test', md5($method . $path)));
        $entryId = $this->mastodonPostFor($user);
        for ($attempt = 0; $attempt < self::WRITE_BUDGET; ++$attempt) {
            $client->request('DELETE', sprintf('/api/entries/%d/repost', $entryId));
        }

        $this->send($client, $method, str_replace('{id}', (string) $entryId, $path), ['text' => 'Hi']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        yield 'reply' => ['POST', '/api/entries/{id}/social/replies'];
        yield 'quote' => ['POST', '/api/entries/{id}/social/quotes'];
        yield 'delete' => ['DELETE', '/api/entries/{id}/comments/reply?address=' . rawurlencode(self::REPLY)];
    }

    #[DataProvider('composers')]
    public function testOpeningAComposerDrawsOnTheViewerBudget(string $path): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('composer-budget-%s@example.test', md5($path)));
        $entryId = $this->mastodonPostFor($user);
        for ($attempt = 0; $attempt < self::VIEWER_BUDGET; ++$attempt) {
            $client->request('GET', sprintf('/api/entries/%d/social/viewer', $entryId));
        }

        $client->request('GET', str_replace('{id}', (string) $entryId, $path));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return iterable<string, array{string}> */
    public static function composers(): iterable
    {
        yield 'response composer' => ['/api/entries/{id}/social/composer'];
    }

    /** @param array<string, string> $body */
    private function postReply(KernelBrowser $client, int $entryId, array $body): void
    {
        $this->send($client, 'POST', sprintf('/api/entries/%d/social/replies', $entryId), $body);
    }

    private function quotePath(int $entryId): string
    {
        return sprintf('/api/entries/%d/social/quotes', $entryId);
    }

    /** @param array<string, string> $body */
    private function send(KernelBrowser $client, string $method, string $path, array $body): void
    {
        $client->request(
            $method,
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function deletePath(int $entryId): string
    {
        return sprintf('/api/entries/%d/comments/reply?address=%s', $entryId, rawurlencode(self::REPLY));
    }

    /** @param array<string, string|array<string, string>> $status */
    private static function statuses(array $status): MockResponse
    {
        return new MockResponse(json_encode(['statuses' => [$status]], \JSON_THROW_ON_ERROR));
    }
}
