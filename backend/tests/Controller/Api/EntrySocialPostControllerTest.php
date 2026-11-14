<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\SharingSettings;
use App\Entity\User;
use App\Repository\EntryShareRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\StoredUserSettings;
use App\Service\Content\Comments\CommentsCache;
use App\Service\Content\Comments\CommentsSource\LemmyCommentsSource;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntrySocialPostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example', 'lemmy.example'];
    private const string LEMMY_FIXTURES = __DIR__ . '/../../Fixtures/lemmy/';
    private const string LEMMY_POST = 'GET https://lemmy.example/api/v3/post?id=42';
    private const string LEMMY_COMMENT = 'POST https://lemmy.example/api/v3/comment';
    private const string LEMMY_COMMENT_ADDRESS = 'https://origin.example/comment/5';
    private const string LEMMY_RESOLVE_COMMENT = 'GET https://lemmy.example/api/v3/resolve_object?q='
        . 'https%3A%2F%2Forigin.example%2Fcomment%2F5';
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
    private const string OWN_PROFILE = 'https://mastodon.example/@alice.rss';
    private const string OWN_STATUS = 'https://mastodon.example/@alice/120';
    private const string DELETE_OWN = 'DELETE https://mastodon.example/api/v1/statuses/120';
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

    public function testTheComposerOpensForALemmyPost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::LEMMY_POST => self::lemmyFixture('post-as-account.json'),
        ]);
        $user = $this->signIn($client, 'composer-lemmy@example.test');
        $account = $this->lemmyAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d/social/composer', $this->lemmyPostFor($user)));

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([['id' => $account->requireId(), 'handle' => '@lars@lemmy.example']], $payload['accounts']);
        self::assertSame('reply', $payload['kind']);
        self::assertTrue($payload['canSend']);
        self::assertSame(10000, $payload['maxCharacters']);
        self::assertNull($payload['visibility']);
        self::assertSame('', $payload['text']);
        self::assertIsArray($payload['target']);
        self::assertSame('Ada', $payload['target']['authorName']);
        self::assertSame([self::LEMMY_POST], $this->routesAsked());
    }

    public function testRepliesToALemmyPost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::LEMMY_POST => self::lemmyFixture('post-as-account.json'),
            self::LEMMY_COMMENT => self::lemmyFixture('comment-create.json'),
        ]);
        $user = $this->signIn($client, 'reply-lemmy-post@example.test');
        $this->lemmyAccountOf($user);

        $this->postReply($client, $this->lemmyPostFor($user), ['text' => 'Hello **there**']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => 'https://lemmy.example/comment/9001', 'url' => 'https://lemmy.example/comment/9001'],
            $this->payload($client),
        );
        self::assertSame('{"content":"Hello **there**","post_id":42}', $this->http->requests[1]['body']);
    }

    public function testRepliesToALemmyComment(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::LEMMY_RESOLVE_COMMENT => self::lemmyFixture('resolve-comment.json'),
            self::LEMMY_COMMENT => self::lemmyFixture('comment-create.json'),
        ]);
        $user = $this->signIn($client, 'reply-lemmy-comment@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);

        $this->send(
            $client,
            'POST',
            sprintf('/api/entries/%d/social/replies?address=%s', $entryId, rawurlencode(self::LEMMY_COMMENT_ADDRESS)),
            ['text' => 'Agreed'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('{"content":"Agreed","post_id":990,"parent_id":77}', $this->http->requests[1]['body']);
    }

    public function testALockedPostCannotBeAnswered(): void
    {
        $locked = (string) file_get_contents(self::LEMMY_FIXTURES . 'post-as-account.json');
        $locked = str_replace('"locked": false', '"locked": true', $locked);
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::LEMMY_POST => new MockResponse($locked)]);
        $user = $this->signIn($client, 'reply-lemmy-locked@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);

        $client->request('GET', sprintf('/api/entries/%d/social/composer', $entryId));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['canSend']);

        $this->postReply($client, $entryId, ['text' => 'Hello']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('social_post_refused', $this->payload($client)['type']);
    }

    public function testALemmyPostCannotBeQuoted(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::LEMMY_POST => self::lemmyFixture('post-as-account.json'),
        ]);
        $user = $this->signIn($client, 'quote-lemmy@example.test');
        $this->lemmyAccountOf($user);
        $entryId = $this->lemmyPostFor($user);

        $this->send($client, 'POST', $this->quotePath($entryId), ['text' => 'Worth reading']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('social_post_refused', $this->payload($client)['type']);
    }

    public function testAReplyForgetsTheCachedThread(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::LEMMY_POST => self::lemmyFixture('post-as-account.json'),
            self::LEMMY_COMMENT => self::lemmyFixture('comment-create.json'),
        ]);
        $user = $this->signIn($client, 'reply-lemmy-cache@example.test');
        $accountId = $this->lemmyAccountOf($user)->requireId();
        $entryId = $this->lemmyPostFor($user);
        $cache = self::getContainer()->get(CommentsCache::class);
        self::assertInstanceOf(CommentsCache::class, $cache);
        $key = LemmyCommentsSource::class . ' https://lemmy.example/post/42 account:' . $accountId;
        $cache->remember($key, CommentsAnswerModel::settled('{}'), new \DateTimeImmutable());

        $this->postReply($client, $entryId, ['text' => 'Hello']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($cache->find($key));
    }

    private static function lemmyFixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::LEMMY_FIXTURES . $name));
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

    public function testAQuoteOfTheEntrysPostRecordsItAsShared(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110', 'quote_approval' => ['current_user' => 'automatic']]),
            self::STATUSES => self::published('201'),
        ]);
        $user = $this->signIn($client, 'quote-shares@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $this->send($client, 'POST', $this->quotePath($entryId), ['text' => 'Worth reading']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNotNull($this->sharedAtOf($user, $entryId));
    }

    /** @param \Closure(SharingSettings): void $turnOff */
    #[DataProvider('switchesThatStopRecording')]
    public function testAQuoteRecordsNothingWhileSharesAreNotRemembered(\Closure $turnOff): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110', 'quote_approval' => ['current_user' => 'automatic']]),
            self::STATUSES => self::published('201'),
        ]);
        $user = $this->signIn($client, sprintf('quote-forgetful-%s@example.test', md5((string) $this->dataName())));
        $turnOff(StoredUserSettings::sharing($this->entityManager(), $user));
        $this->entityManager()->flush();
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $this->send($client, 'POST', $this->quotePath($entryId), ['text' => 'Worth reading']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->sharedAtOf($user, $entryId));
    }

    /** @return iterable<string, array{\Closure(SharingSettings): void}> */
    public static function switchesThatStopRecording(): iterable
    {
        yield 'remembering off' => [static fn (SharingSettings $settings) => $settings->setRememberingShares(false)];
        yield 'sharing off' => [static fn (SharingSettings $settings) => $settings->setEnabled(false)];
    }

    public function testAQuoteOfAReplyInTheThreadLeavesTheEntryUnshared(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::REPLY_SEARCH => self::statuses(['id' => '200', 'quote_approval' => ['current_user' => 'automatic']]),
            self::STATUSES => self::published('201'),
        ]);
        $user = $this->signIn($client, 'quote-reply-unshared@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $this->send($client, 'POST', $this->quotePath($entryId) . '?address=' . rawurlencode(self::REPLY), [
            'text' => 'Worth reading',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->sharedAtOf($user, $entryId));
    }

    public function testAReplyToTheEntrysPostLeavesItUnshared(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::statuses(['id' => '110']),
            self::STATUSES => self::published('200'),
        ]);
        $user = $this->signIn($client, 'reply-unshared@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $this->postReply($client, $entryId, ['text' => 'Thanks']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->sharedAtOf($user, $entryId));
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
        yield 'delete post' => ['DELETE', '/api/entries/{id}/post'];
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

    public function testMyOwnPostIsDeletedThereAndHere(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}'),
        ]);
        $user = $this->signIn($client, 'own-post-delete@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'deleted'], $this->payload($client));
        self::assertSame([self::DELETE_OWN], $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testTheArticleSaysWhichPostIsMine(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'own-post-article@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d', $this->ownPostFor($user)));
        self::assertTrue($this->socialOf($client)['isOwn']);
        $client->request('GET', sprintf('/api/entries/%d', $this->mastodonPostFor($user)));
        self::assertFalse($this->socialOf($client)['isOwn']);
    }

    public function testAPostAlreadyGoneThereIsRemovedHereAndSaysSo(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);
        $user = $this->signIn($client, 'own-post-gone@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['outcome' => 'alreadyGone'], $this->payload($client));
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testSomeoneElsesPostIsRefusedAndKept(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'own-post-foreign@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame([], $this->http->requests);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    public function testAnUnreachableNetworkKeepsThePost(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::DELETE_OWN => new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ]);
        $user = $this->signIn($client, 'own-post-down@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->ownPostFor($user);

        $client->request('DELETE', sprintf('/api/entries/%d/post', $entryId));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    private function ownPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());

        return $fixtures->post($fixtures->subscribe($user, self::OWN_PROFILE), self::OWN_STATUS, '2026-10-12T10:00:00Z')
            ->requireId();
    }

    /** @return array{network: string, isLiked: bool, canLike: bool, isOwn: bool, openUrl: string|null} */
    private function socialOf(KernelBrowser $client): array
    {
        $entry = $this->payload($client)['entry'];
        self::assertIsArray($entry);
        self::assertIsArray($entry['social']);

        /** @var array{network: string, isLiked: bool, canLike: bool, isOwn: bool, openUrl: string|null} $social */
        $social = $entry['social'];

        return $social;
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

    private static function published(string $statusId): MockResponse
    {
        return new MockResponse(json_encode(
            ['id' => $statusId, 'uri' => 'https://mastodon.example/users/alice/statuses/' . $statusId],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private function sharedAtOf(User $user, int $entryId): ?\DateTimeImmutable
    {
        $shares = $this->entityManager()->getRepository(EntryShare::class);
        self::assertInstanceOf(EntryShareRepository::class, $shares);

        return $shares->sharedAtFor($user->requireId(), $entryId);
    }
}
