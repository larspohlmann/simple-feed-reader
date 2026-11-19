<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Dto\Social\LinkTargetQuery;
use App\Entity\LinkCardContent;
use App\Service\Social\Bluesky\BlueskyPostWriter;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RoutesSocialApis;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SocialLinkPostControllerTest extends ApiTestCase
{
    use RoutesSocialApis;

    private const array ROUTED_HOSTS = ['mastodon.example'];
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string STATUSES = 'POST https://mastodon.example/api/v1/statuses';
    private const string LINK_COMPOSER = '/api/social/link-composer?url=https%3A%2F%2Fexample.com%2Farticle&network=';
    private const string STATUS_SEARCH = 'GET https://mastodon.example/api/v2/search?q='
        . 'https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110&resolve=true&type=statuses&limit=1';
    private const string LINKS = '/api/social/links?network=';
    private const string MASTODON_LINKS = '/api/social/links?network=mastodon&url=';
    private const string ARTICLE_URL = 'https://example.com/article?id=7';
    private const string LINK_POST = 'https://mastodon.example/users/alice/statuses/202';
    private const string LINK_POST_URL = 'https://mastodon.example/@alice/202';
    private const string STATUS_COMPOSER = '/api/social/link-composer?network=mastodon'
        . '&url=https%3A%2F%2Fmastodon.social%2F%40Gargron%2F110';
    private const array ARTICLE_HOST_CARD = ['kind' => 'link', 'card' => [
        'url' => 'https://example.com/article',
        'title' => 'example.com',
        'description' => null,
        'imageUrl' => null,
        'siteName' => 'example.com',
    ]];
    private const string PDS_SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string PDS_CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string BLUESKY_POST_URL = 'https://bsky.app/profile/bob.test/post/3abc';
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

        $client->request('GET', self::LINK_COMPOSER . 'mastodon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('POST', self::LINKS . 'mastodon');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheLinkComposerStartsFromTheMastodonAccountsOwnDefaults(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":1000}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"unlisted"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-mastodon@example.test');
        $account = $this->mastodonAccountOf($user);

        $client->request('GET', self::LINK_COMPOSER . 'mastodon&title=The+article');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => '@alice@mastodon.example']],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => 975,
            'visibility' => 'unlisted',
            'lemmy' => null,
            'kind' => 'link',
            'text' => 'The article',
            'target' => self::ARTICLE_HOST_CARD,
        ], $this->payload($client));
    }

    public function testTheLinkComposerOnLemmyOffersThePostableCommunitiesAndThePageTitle(): void
    {
        $communities = json_encode(['communities' => [
            ['community' => ['id' => 7, 'name' => 'rss', 'actor_id' => 'https://lemmy.world/c/rss']],
        ]], \JSON_THROW_ON_ERROR);
        $client = $this->clientRouting(['lemmy.example'], [
            'GET https://lemmy.example/api/v3/community/list?type_=Subscribed&sort=Old&limit=50&page=1'
                => new MockResponse($communities),
        ]);
        $user = $this->signIn($client, 'link-composer-lemmy@example.test');
        $this->lemmyAccountOf($user);

        $client->request('GET', self::LINK_COMPOSER . 'lemmy&title=The+article');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('', $payload['text']);
        self::assertSame(10000, $payload['maxCharacters']);
        self::assertSame([
            'communities' => [['id' => 7, 'handle' => '!rss@lemmy.world']],
            'title' => 'The article',
            'minTitleCharacters' => 3,
            'maxTitleCharacters' => 200,
        ], $payload['lemmy']);
    }

    public function testTheLinkComposerOnBlueskyStartsFromItsGraphemeLimit(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-bluesky@example.test');
        $account = SocialAccounts::blueskyOf($user);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $client->request('GET', self::LINK_COMPOSER . 'bluesky&title=The+article');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accounts' => [['id' => $account->requireId(), 'handle' => $account->getHandle()]],
            'accountId' => $account->requireId(),
            'canSend' => true,
            'maxCharacters' => BlueskyPostWriter::MAX_GRAPHEMES,
            'visibility' => null,
            'lemmy' => null,
            'kind' => 'link',
            'text' => 'The article',
            'target' => self::ARTICLE_HOST_CARD,
        ], $this->payload($client));
        self::assertSame([], $this->http->requests);
    }

    public function testTheLinkComposerStartsWithTheSelectedQuoteAloneOnMastodon(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":500}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"public"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-quote-mastodon@example.test');
        $this->mastodonAccountOf($user);

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'mastodon&title=The+article&quote=' . rawurlencode('A line worth sharing'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('“A line worth sharing”', $this->payload($client)['text']);
        self::assertSame(475, $this->payload($client)['maxCharacters']);
    }

    public function testTheLinkComposerStartsWithTheSelectedQuoteAloneOnBluesky(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-quote-bluesky@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'bluesky&title=The+article&quote=' . rawurlencode('A line worth sharing'),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('“A line worth sharing”', $this->payload($client)['text']);
    }

    public function testTheLinkComposerKeepsTheTextFragmentThatPointsAtTheQuote(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{"configuration":{"statuses":{"max_characters":500}}}'),
            self::OWN_ACCOUNT => new MockResponse('{"id":"1","source":{"privacy":"public"}}'),
        ]);
        $user = $this->signIn($client, 'link-composer-quote-fragment@example.test');
        $this->mastodonAccountOf($user);
        $page = 'https://example.com/article#:~:text=A%20line%20worth%20sharing';

        $client->request('GET', '/api/social/link-composer?network=mastodon&url=' . rawurlencode($page)
            . '&quote=' . rawurlencode('A line worth sharing'));

        self::assertResponseIsSuccessful();
        self::assertSame('“A line worth sharing”', $this->payload($client)['text']);
    }

    public function testTheLinkComposerRefusesAQuoteLongerThanTheFrontendSends(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-quote-long@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();

        $client->request(
            'GET',
            self::LINK_COMPOSER . 'bluesky&quote=' . str_repeat('a', LinkTargetQuery::QUOTE_MAX + 1),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheLinkComposerStartsFromALongTitleClipped(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-long-title@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $this->entityManager()->flush();

        $client->request('GET', self::LINK_COMPOSER . 'bluesky&title=' . rawurlencode(str_repeat('ä', 1000)));

        self::assertResponseIsSuccessful();
        self::assertSame(str_repeat('ä', LinkCardContent::TITLE_MAX), $this->payload($client)['text']);
    }

    public function testTheLinkComposerWritesAsTheAccountTheReaderChose(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-composer-chosen@example.test');
        $this->entityManager()->persist(SocialAccounts::blueskyOf($user));
        $chosen = SocialAccounts::secondBlueskyOf($user);
        $this->entityManager()->persist($chosen);
        $this->entityManager()->flush();

        $client->request('GET', self::LINK_COMPOSER . 'bluesky&accountId=' . $chosen->requireId());

        self::assertResponseIsSuccessful();
        self::assertSame($chosen->requireId(), $this->payload($client)['accountId']);
    }

    #[DataProvider('missingNetworks')]
    public function testTheLinkComposerAndALinkPostNeedAKnownNetwork(string $query): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('link-network-%s@example.test', md5($query)));
        $this->mastodonAccountOf($user);

        $client->request('GET', '/api/social/link-composer?url=' . rawurlencode(self::ARTICLE_URL) . $query);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->send($client, '/api/social/links?url=' . rawurlencode(self::ARTICLE_URL) . $query, ['text' => 'Hi']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function missingNetworks(): iterable
    {
        yield 'no network' => [''];
        yield 'unknown network' => ['&network=friendica'];
    }

    public function testALinkPostIsPublishedOnMastodonWithTheComposersChoices(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::STATUSES => new MockResponse(json_encode(
                ['id' => '202', 'uri' => self::LINK_POST, 'url' => 'https://mastodon.example/@alice/202'],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
        $user = $this->signIn($client, 'link-post@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::MASTODON_LINKS . rawurlencode(self::ARTICLE_URL), [
            'text' => 'Worth reading ' . self::ARTICLE_URL,
            'visibility' => 'unlisted',
            'idempotencyKey' => self::IDEMPOTENCY_KEY,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => self::LINK_POST, 'url' => 'https://mastodon.example/@alice/202'],
            $this->payload($client),
        );
        self::assertSame([self::STATUSES], $this->routesAsked());
        self::assertSame(
            ['status' => 'Worth reading ' . self::ARTICLE_URL, 'visibility' => 'unlisted'],
            json_decode($this->http->requests[0]['body'], true),
        );
        self::assertContains('Idempotency-Key: ' . self::IDEMPOTENCY_KEY, $this->http->requests[0]['headers']);
    }

    public function testBlankTextLinkPostIsPublishedOnBluesky(): void
    {
        $client = $this->clientRouting([...self::ROUTED_HOSTS, 'pds.example.test'], [
            self::PDS_SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::PDS_CREATE_RECORD => new MockResponse(
                '{"uri":"at://did:plc:alice/app.bsky.feed.post/3new","cid":"bafy-new"}',
            ),
        ]);
        $user = $this->signIn($client, 'link-blank-bluesky@example.test');
        $this->blueskyAccountOf($user);

        $this->send($client, self::LINKS . 'bluesky&url=' . rawurlencode(self::ARTICLE_URL), ['text' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $createRecord = array_last($this->http->requests);
        self::assertIsArray($createRecord);
        $created = json_decode($createRecord['body'], true);
        self::assertIsArray($created);
        self::assertIsArray($created['record']);
        self::assertSame('', $created['record']['text']);
        self::assertIsArray($created['record']['embed']);
        self::assertSame('app.bsky.embed.external', $created['record']['embed']['$type']);
    }

    public function testBlankTextLinkPostIsPublishedOnMastodon(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::STATUSES => self::published()]);
        $user = $this->signIn($client, 'link-blank-mastodon@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::MASTODON_LINKS . rawurlencode(self::ARTICLE_URL), ['text' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['status' => 'https://example.com/article?id=7'],
            json_decode($this->http->requests[0]['body'], true),
        );
    }

    public function testALinkPostIsPublishedOnLemmyInTheChosenCommunity(): void
    {
        $client = $this->clientRouting(['lemmy.example'], [
            'POST https://lemmy.example/api/v3/post' => new MockResponse(
                '{"post_view":{"post":{"id":501,"ap_id":"https://lemmy.example/post/501"}}}',
            ),
        ]);
        $user = $this->signIn($client, 'link-post-lemmy@example.test');
        $this->lemmyAccountOf($user);

        $this->send($client, self::LINKS . 'lemmy&url=' . rawurlencode(self::ARTICLE_URL), [
            'text' => 'Worth reading',
            'title' => 'The article',
            'communityId' => 7,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(
            ['address' => 'https://lemmy.example/post/501', 'url' => 'https://lemmy.example/post/501'],
            $this->payload($client),
        );
        self::assertSame(
            [
                'name' => 'The article',
                'url' => self::ARTICLE_URL,
                'body' => 'Worth reading',
                'community_id' => 7,
            ],
            json_decode($this->http->requests[0]['body'], true),
        );
    }

    public function testALemmyLinkPostWithoutACommunityIsRefused(): void
    {
        $client = $this->clientRouting(['lemmy.example'], []);
        $user = $this->signIn($client, 'link-post-lemmy-bare@example.test');
        $this->lemmyAccountOf($user);

        $this->send(
            $client,
            self::LINKS . 'lemmy&url=' . rawurlencode(self::ARTICLE_URL),
            ['text' => '', 'title' => 'Title'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_post_community_missing', $this->payload($client)['type']);
    }

    public function testBlankTextOnAQuotedLinkIsRefused(): void
    {
        $client = $this->clientRouting([...self::ROUTED_HOSTS, 'pds.example.test', 'public.api.bsky.app'], [
            'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=bob.test'
                => new MockResponse('{"did":"did:plc:bob"}'),
            self::PDS_SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            'GET https://pds.example.test/xrpc/app.bsky.feed.getPosts?uris='
                . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.post%2F3abc' => new MockResponse(
                    '{"posts":[{"uri":"at://did:plc:bob/app.bsky.feed.post/3abc","cid":"bafy-bob"}]}',
                ),
        ]);
        $user = $this->signIn($client, 'link-blank-quote@example.test');
        $this->blueskyAccountOf($user);

        $this->send($client, self::LINKS . 'bluesky&url=' . rawurlencode(self::BLUESKY_POST_URL), ['text' => '  ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_post_text_missing', $this->payload($client)['type']);
    }

    #[DataProvider('invalidLinkPosts')]
    public function testALinkPostWithoutASharableUrlIsRejectedBeforeAnyCall(string $query, string $text): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-invalid-' . md5($query . $text) . '@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::LINKS . 'mastodon' . $query, ['text' => $text]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLinkPosts(): iterable
    {
        yield 'no url' => ['', 'Hi'];
        yield 'a blank url' => ['&url=', 'Hi'];
        yield 'no url at all' => ['&url=not+a+link', 'Hi'];
        yield 'no http url' => ['&url=' . rawurlencode('ftp://example.com/file'), 'Hi'];
        yield 'a host without tld' => ['&url=' . rawurlencode('https://intranet/page'), 'Hi'];
        yield 'a too long url' => [
            '&url=' . rawurlencode('https://example.com/' . str_repeat('a', LinkCardContent::URL_MAX)),
            'Hi',
        ];
    }

    public function testALinkPostWithAnUnknownVisibilityIsRejectedBeforeAnyCall(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'link-invalid-visibility@example.test');
        $this->mastodonAccountOf($user);

        $this->send(
            $client,
            self::LINKS . 'mastodon&url=' . rawurlencode(self::ARTICLE_URL),
            ['text' => 'Hi', 'visibility' => 'everyone'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidComposerQueries(): iterable
    {
        yield 'no url' => [''];
        yield 'no url at all' => ['&url=not+a+link'];
        yield 'a host without tld' => ['&url=https%3A%2F%2Fintranet%2Fpage'];
    }

    #[DataProvider('invalidComposerQueries')]
    public function testTheLinkComposerNeedsASharableUrl(string $query): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, sprintf('link-composer-url-%s@example.test', md5($query)));
        $this->mastodonAccountOf($user);

        $client->request('GET', '/api/social/link-composer?network=mastodon' . $query);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->http->requests);
    }

    public function testTheLinkComposerForAStatusOnTheAccountsNetworkShowsTheStatusItQuotes(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{}'),
            self::OWN_ACCOUNT => new MockResponse('{}'),
            self::STATUS_SEARCH => self::statuses([...self::quotableStatus(), 'card' => [
                'url' => 'https://example.org/story',
                'title' => 'The story',
                'provider_name' => 'Example',
            ]]),
        ]);
        $user = $this->signIn($client, 'link-composer-quote@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', self::STATUS_COMPOSER);

        self::assertResponseIsSuccessful();
        self::assertSame(500, $this->payload($client)['maxCharacters']);
        self::assertSame(
            [
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
            $this->payload($client)['target'],
        );
    }

    public function testAStatusSharedOnItsOwnNetworkIsQuoted(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::STATUS_SEARCH => self::statuses(self::quotableStatus()),
            self::STATUSES => self::published(),
        ]);
        $user = $this->signIn($client, 'link-quote@example.test');
        $this->mastodonAccountOf($user);

        $this->send($client, self::MASTODON_LINKS . rawurlencode(self::MASTODON_POST_URL), ['text' => 'Look']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['address' => self::LINK_POST, 'url' => self::LINK_POST_URL], $this->payload($client));
        self::assertSame([self::STATUS_SEARCH, self::STATUSES], $this->routesAsked());
        self::assertSame(
            ['status' => 'Look', 'quoted_status_id' => '110'],
            json_decode($this->http->requests[1]['body'], true),
        );
    }

    /** @return iterable<string, array{MockResponse, list<string>}> */
    public static function unquotableStatuses(): iterable
    {
        $once = [self::STATUS_SEARCH, self::STATUSES];

        yield 'the author forbids quoting' => [self::statuses(['id' => '110']), $once];
        yield 'the instance cannot find it' => [new MockResponse('{"statuses":[]}'), $once];
        yield 'the instance is down' => [
            new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            [self::STATUS_SEARCH, self::STATUS_SEARCH, self::STATUS_SEARCH, self::STATUSES],
        ];
    }

    /** @param list<string> $expectedRoutes */
    #[DataProvider('unquotableStatuses')]
    public function testAStatusThatCannotBeQuotedIsSharedAsALink(MockResponse $search, array $expectedRoutes): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::STATUS_SEARCH => $search,
            self::STATUSES => self::published(),
        ]);
        $user = $this->signIn($client, sprintf('link-unquotable-%s@example.test', spl_object_id($search)));
        $this->mastodonAccountOf($user);

        $this->send($client, self::MASTODON_LINKS . rawurlencode(self::MASTODON_POST_URL), ['text' => 'Look']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($expectedRoutes, $this->routesAsked());
        self::assertSame(
            ['status' => "Look\n\n" . self::MASTODON_POST_URL],
            json_decode(end($this->http->requests)['body'] ?? '', true),
        );
    }

    public function testABlueskyPostSharedOnMastodonIsALinkPostAndItsComposerQuotesNothing(): void
    {
        $blueskyPost = 'https://bsky.app/profile/bob.test/post/3abc';
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::INSTANCE => new MockResponse('{}'),
            self::OWN_ACCOUNT => new MockResponse('{}'),
            self::STATUSES => self::published(),
        ]);
        $user = $this->signIn($client, 'link-cross-network@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', '/api/social/link-composer?network=mastodon&url=' . rawurlencode($blueskyPost));
        $target = $this->payload($client)['target'];
        self::assertIsArray($target);
        self::assertSame('link', $target['kind']);

        $this->send($client, self::MASTODON_LINKS . rawurlencode($blueskyPost), ['text' => 'Look']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([self::INSTANCE, self::OWN_ACCOUNT, self::STATUSES], $this->routesAsked());
        self::assertSame(
            ['status' => "Look\n\n" . $blueskyPost],
            json_decode($this->http->requests[2]['body'], true),
        );
    }

    public function testALinkPostDrawsOnTheWriteBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'link-write-budget@example.test');
        for ($attempt = 0; $attempt < self::WRITE_BUDGET; ++$attempt) {
            $this->send($client, self::MASTODON_LINKS . rawurlencode(self::ARTICLE_URL), ['text' => 'Hi']);
        }

        $this->send($client, self::MASTODON_LINKS . rawurlencode(self::ARTICLE_URL), ['text' => 'Hi']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testOpeningTheLinkComposerDrawsOnTheViewerBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $this->signIn($client, 'link-viewer-budget@example.test');
        for ($attempt = 0; $attempt < self::VIEWER_BUDGET; ++$attempt) {
            $client->request('GET', self::LINK_COMPOSER . 'mastodon');
        }

        $client->request('GET', self::LINK_COMPOSER . 'mastodon');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return array<string, mixed> */
    private static function quotableStatus(): array
    {
        return [
            'id' => '110',
            'quote_approval' => ['current_user' => 'automatic'],
            'account' => [
                'display_name' => 'Eugen',
                'acct' => 'Gargron@mastodon.social',
                'url' => 'https://mastodon.social/@Gargron',
            ],
            'content' => '<p>Hello <b>world</b></p>',
        ];
    }

    /** @param array<string, mixed> $status */
    private static function statuses(array $status): MockResponse
    {
        return new MockResponse(json_encode(['statuses' => [$status]], \JSON_THROW_ON_ERROR));
    }

    private static function published(): MockResponse
    {
        return new MockResponse(json_encode(
            ['id' => '202', 'uri' => self::LINK_POST, 'url' => self::LINK_POST_URL],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string, int|string> $body */
    private function send(KernelBrowser $client, string $path, array $body): void
    {
        $client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
