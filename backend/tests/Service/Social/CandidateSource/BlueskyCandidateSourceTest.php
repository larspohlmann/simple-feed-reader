<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\CandidateSource\BlueskyCandidateSource;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BlueskyCandidateSourceTest extends TestCase
{
    use AssignsEntityIds;

    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string PREFERENCES = 'GET https://pds.example.test/xrpc/app.bsky.actor.getPreferences';
    private const string FOLLOWS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string LISTS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getLists'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string GENERATORS = 'GET https://public.api.bsky.app/xrpc/app.bsky.feed.getFeedGenerators?';
    private const string DISCOVER = 'at://did:plc:whats-hot/app.bsky.feed.generator/discover';
    private const string GONE = 'at://did:plc:retired/app.bsky.feed.generator/gone';

    private ArrayAdapter $cache;
    private SocialAccount $account;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->account = SocialAccounts::bluesky();
        self::assignId($this->account, 3);
    }

    private function source(RoutedHttpClient $http): BlueskyCandidateSource
    {
        return $this->sourceOver($http->client());
    }

    private function sourceOver(HttpClientInterface $http): BlueskyCandidateSource
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver(['public.api.bsky.app' => $address, 'pds.example.test' => $address]),
            new IpValidator(),
        );
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, $guard));

        return new BlueskyCandidateSource(
            $api,
            new BlueskySessions(
                $api,
                new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
                $this->sessionCache(),
            ),
        );
    }

    private function sessionCache(): BlueskySessionCache
    {
        return new BlueskySessionCache(
            $this->cache,
            new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))),
        );
    }

    /**
     * @param array<string, MockResponse> $overrides
     *
     * @return array<string, MockResponse>
     */
    private function routes(array $overrides = []): array
    {
        return $overrides + [
            self::SESSION => new MockResponse(
                '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}',
            ),
            self::PREFERENCES => self::json(['preferences' => []]),
            self::FOLLOWS => self::json(['follows' => []]),
            self::LISTS => self::json(['lists' => []]),
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $uris */
    private static function savedFeeds(array $uris): MockResponse
    {
        $items = array_map(static fn (string $uri): array => ['type' => 'feed', 'value' => $uri], $uris);

        return self::json(['preferences' => [['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => $items]]]);
    }

    /** @param list<string> $uris */
    private static function feedsQuery(array $uris): string
    {
        return implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $uris));
    }

    /** @return list<array{did: string, handle: string}> */
    private static function manyFollows(int $count): array
    {
        return array_map(
            static fn (int $index): array => ['did' => 'did:plc:f' . $index, 'handle' => 'f' . $index . '.bsky.social'],
            range(1, $count),
        );
    }

    public function testTheNetworkKeyIsBluesky(): void
    {
        self::assertSame('bluesky', BlueskyCandidateSource::network());
    }

    public function testFollowsArePagedByCursorAndReadAsDidKeyedProfileFeeds(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => [
                    [
                        'did' => 'did:plc:bob',
                        'handle' => 'bob.example.com',
                        'displayName' => 'Bob',
                        'avatar' => 'https://cdn.bsky.app/img/avatar/bob.jpg',
                    ],
                    ['handle' => 'no-did.example.com'],
                ],
                'cursor' => 'page 2',
            ]),
            self::FOLLOWS . '&cursor=page%202' => self::json([
                'follows' => [[
                    'did' => 'did:plc:carol',
                    'handle' => 'carol.bsky.social',
                    'displayName' => '',
                    'avatar' => 'not a url',
                ]],
            ]),
        ]));

        $candidates = $this->source($http)->candidates($this->account);

        self::assertEquals([
            new FollowedAccountModel(
                'did:plc:bob',
                '@bob.example.com',
                'Bob',
                'https://bsky.app/profile/did:plc:bob/rss',
                'https://cdn.bsky.app/img/avatar/bob.jpg',
            ),
            new FollowedAccountModel(
                'did:plc:carol',
                '@carol.bsky.social',
                null,
                'https://bsky.app/profile/did:plc:carol/rss',
                null,
            ),
        ], $candidates->follows);
        self::assertFalse($candidates->truncated);
    }

    public function testOnlyCuratedListsAreOffered(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => self::json(['lists' => [
                [
                    'uri' => 'at://did:plc:alice/app.bsky.graph.list/news',
                    'name' => 'News',
                    'purpose' => 'app.bsky.graph.defs#curatelist',
                ],
                [
                    'uri' => 'at://did:plc:alice/app.bsky.graph.list/blocks',
                    'name' => 'Blocks',
                    'purpose' => 'app.bsky.graph.defs#modlist',
                ],
            ]]),
        ]));

        $lists = $this->source($http)->candidates($this->account)->lists;

        self::assertEquals([new SocialCollectionModel('at://did:plc:alice/app.bsky.graph.list/news', 'News')], $lists);
    }

    public function testEveryCuratedListWithAUriAndANameIsOffered(): void
    {
        $curated = 'app.bsky.graph.defs#curatelist';
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => self::json(['lists' => [
                ['uri' => 'at://did:plc:alice/app.bsky.graph.list/news', 'name' => 'News', 'purpose' => $curated],
                ['uri' => 'at://did:plc:alice/app.bsky.graph.list/art', 'name' => 'Art', 'purpose' => $curated],
                ['uri' => 'at://did:plc:alice/app.bsky.graph.list/unnamed', 'purpose' => $curated],
                ['name' => 'Nowhere', 'purpose' => $curated],
            ]]),
        ]));

        $lists = $this->source($http)->candidates($this->account)->lists;

        self::assertEquals([
            new SocialCollectionModel('at://did:plc:alice/app.bsky.graph.list/news', 'News'),
            new SocialCollectionModel('at://did:plc:alice/app.bsky.graph.list/art', 'Art'),
        ], $lists);
    }

    public function testANamelessRepeatOfAGeneratorKeepsItsName(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedFeeds([self::DISCOVER, self::GONE]),
            self::GENERATORS . self::feedsQuery([self::DISCOVER, self::GONE]) => self::json(['feeds' => [
                ['uri' => self::DISCOVER, 'displayName' => 'Discover'],
                ['uri' => self::GONE, 'displayName' => 'Gone'],
                ['uri' => self::DISCOVER],
            ]]),
        ]));

        $customFeeds = $this->source($http)->candidates($this->account)->customFeeds;

        self::assertEquals([
            new SocialCollectionModel(self::DISCOVER, 'Discover'),
            new SocialCollectionModel(self::GONE, 'Gone'),
        ], $customFeeds);
    }

    public function testSavedFeedsAreNamedByTheirGeneratorAndAnUnknownOneKeepsItsUri(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::json(['preferences' => [
                ['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false],
                ['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => [
                    ['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => '1'],
                    ['type' => 'feed', 'value' => self::DISCOVER, 'pinned' => true, 'id' => '2'],
                    ['type' => 'feed', 'value' => self::GONE, 'pinned' => false, 'id' => '3'],
                    [
                        'type' => 'list',
                        'value' => 'at://did:plc:alice/app.bsky.graph.list/news',
                        'pinned' => false,
                        'id' => '4',
                    ],
                ]],
            ]]),
            self::GENERATORS . 'feeds=' . rawurlencode(self::DISCOVER) . '&feeds=' . rawurlencode(self::GONE)
                => self::json(['feeds' => [['uri' => self::DISCOVER, 'displayName' => 'Discover']]]),
        ]));

        $customFeeds = $this->source($http)->candidates($this->account)->customFeeds;

        self::assertEquals([
            new SocialCollectionModel(self::DISCOVER, 'Discover'),
            new SocialCollectionModel(self::GONE, self::GONE),
        ], $customFeeds);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    public function testTheCachedSessionReadsThePreferencesWithoutOpeningANewOne(): void
    {
        $this->sessionCache()->remember($this->account, new BlueskySessionModel('cached-jwt', 'alice.bsky.social'));
        $http = new RoutedHttpClient($this->routes());

        $this->source($http)->candidates($this->account);

        self::assertNotContains(
            'https://pds.example.test/xrpc/com.atproto.server.createSession',
            $http->requestedUrls(),
        );
        self::assertContains('Authorization: Bearer cached-jwt', $http->requests[0]['headers']);
    }

    public function testAStaleCachedSessionIsReplacedAndThePreferencesAskedAgain(): void
    {
        $this->sessionCache()->remember($this->account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social'));
        $retry = self::json(['preferences' => []]);
        $http = new MockHttpClient([
            new MockResponse('{"error":"ExpiredToken"}', ['http_code' => Response::HTTP_BAD_REQUEST]),
            new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-2"}'),
            $retry,
            self::json(['follows' => []]),
            self::json(['lists' => []]),
        ]);

        $this->sourceOver($http)->candidates($this->account);

        self::assertSame('https://pds.example.test/xrpc/app.bsky.actor.getPreferences', $retry->getRequestUrl());
        $headers = $retry->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer jwt-2', $headers);
    }

    public function testGeneratorNamesAreAskedForTwentyFiveFeedsAtATime(): void
    {
        $uris = array_map(
            static fn (int $index): string => 'at://did:plc:maker/app.bsky.feed.generator/f' . $index,
            range(1, 26),
        );
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::savedFeeds($uris),
            self::GENERATORS . self::feedsQuery(\array_slice($uris, 0, 25)) => self::json(['feeds' => []]),
            self::GENERATORS . self::feedsQuery(\array_slice($uris, 25)) => self::json(['feeds' => []]),
        ]));

        $customFeeds = $this->source($http)->candidates($this->account)->customFeeds;

        self::assertCount(26, $customFeeds);
        $generatorCalls = array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getFeedGenerators'),
        );
        self::assertCount(2, $generatorCalls);
    }

    public function testFollowsBeyondTheCapAreCutAndMarkedTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => self::manyFollows(CollectedItemsModel::MAX_ITEMS + 1),
                'cursor' => 'more',
            ]),
        ]));

        $candidates = $this->source($http)->candidates($this->account);

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
        self::assertNotContains(
            'https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows?actor=did%3Aplc%3Aalice&limit=100&cursor=more',
            $http->requestedUrls(),
        );
    }

    public function testExactlyTheCapStillAsksForTheNextPageAndIsNotTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => self::manyFollows(CollectedItemsModel::MAX_ITEMS),
                'cursor' => 'more',
            ]),
            self::FOLLOWS . '&cursor=more' => self::json(['follows' => []]),
        ]));

        $candidates = $this->source($http)->candidates($this->account);

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertFalse($candidates->truncated);
        self::assertContains(
            'https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows?actor=did%3Aplc%3Aalice&limit=100&cursor=more',
            $http->requestedUrls(),
        );
    }

    public function testAnEmptyPageEndsPagingEvenWithACursor(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json(['follows' => [], 'cursor' => 'again']),
        ]));

        $this->source($http)->candidates($this->account);

        $followCalls = array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getFollows'),
        );
        self::assertCount(1, $followCalls);
    }

    public function testARefusedAppPasswordIsRejectedBeforeAnyPublicCall(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::SESSION => new MockResponse(
                '{"error":"AuthenticationRequired"}',
                ['http_code' => Response::HTTP_UNAUTHORIZED],
            ),
        ]));

        try {
            $this->source($http)->candidates($this->account);
            self::fail('A refused app password must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testARefusedSessionWhileReadingThePreferencesIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => new MockResponse(
                '{"error":"AuthenticationRequired"}',
                ['http_code' => Response::HTTP_UNAUTHORIZED],
            ),
        ]));

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->candidates($this->account);
    }

    public function testAFailingPublicCallIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => new MockResponse('{"lists":[]}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('Bluesky answered 503.');

        $this->source($http)->candidates($this->account);
    }

    /** @return iterable<string, array{array<string, MockResponse>, string}> */
    public static function answersWithoutTheirCollection(): iterable
    {
        yield 'follows that are not JSON' => [
            [self::FOLLOWS => new MockResponse('Down for maintenance')],
            'Bluesky answered without follows.',
        ];
        yield 'follows without the field' => [
            [self::FOLLOWS => self::json(['cursor' => 'more'])],
            'Bluesky answered without follows.',
        ];
        yield 'follows that are no list' => [
            [self::FOLLOWS => self::json(['follows' => ['did' => 'did:plc:bob']])],
            'Bluesky answered without follows.',
        ];
        yield 'lists without the field' => [
            [self::LISTS => self::json(['error' => 'InternalServerError'])],
            'Bluesky answered without lists.',
        ];
        yield 'generators without the field' => [
            [
                self::PREFERENCES => self::savedFeeds([self::DISCOVER]),
                self::GENERATORS . self::feedsQuery([self::DISCOVER]) => self::json([]),
            ],
            'Bluesky answered without feeds.',
        ];
    }

    /** @param array<string, MockResponse> $overrides */
    #[DataProvider('answersWithoutTheirCollection')]
    public function testASuccessfulAnswerWithoutItsCollectionIsUnreachable(array $overrides, string $message): void
    {
        $http = new RoutedHttpClient($this->routes($overrides));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage($message);

        $this->source($http)->candidates($this->account);
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => new MockResponse('', ['error' => 'Connection refused']),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates($this->account);
    }
}
