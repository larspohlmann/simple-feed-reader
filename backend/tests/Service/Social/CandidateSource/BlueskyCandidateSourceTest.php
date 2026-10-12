<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\CandidateSource;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\CandidateSource\BlueskyCandidateSource;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyCandidateSourceTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string PREFERENCES = 'GET https://pds.example.test/xrpc/app.bsky.actor.getPreferences';
    private const string FOLLOWS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string LISTS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getLists'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string GENERATORS = 'GET https://public.api.bsky.app/xrpc/app.bsky.feed.getFeedGenerators?';
    private const string DISCOVER = 'at://did:plc:whats-hot/app.bsky.feed.generator/discover';
    private const string GONE = 'at://did:plc:retired/app.bsky.feed.generator/gone';

    private function source(RoutedHttpClient $http): BlueskyCandidateSource
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver(['public.api.bsky.app' => $address, 'pds.example.test' => $address]),
            new IpValidator(),
        );
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new BlueskyCandidateSource(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
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
                    ['did' => 'did:plc:bob', 'handle' => 'bob.example.com', 'displayName' => 'Bob'],
                    ['handle' => 'no-did.example.com'],
                ],
                'cursor' => 'page 2',
            ]),
            self::FOLLOWS . '&cursor=page%202' => self::json([
                'follows' => [['did' => 'did:plc:carol', 'handle' => 'carol.bsky.social', 'displayName' => '']],
            ]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

        self::assertEquals([
            new FollowedAccountModel(
                'did:plc:bob',
                '@bob.example.com',
                'Bob',
                'https://bsky.app/profile/did:plc:bob/rss',
            ),
            new FollowedAccountModel(
                'did:plc:carol',
                '@carol.bsky.social',
                null,
                'https://bsky.app/profile/did:plc:carol/rss',
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

        $lists = $this->source($http)->candidates(SocialAccounts::bluesky())->lists;

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

        $lists = $this->source($http)->candidates(SocialAccounts::bluesky())->lists;

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

        $customFeeds = $this->source($http)->candidates(SocialAccounts::bluesky())->customFeeds;

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

        $customFeeds = $this->source($http)->candidates(SocialAccounts::bluesky())->customFeeds;

        self::assertEquals([
            new SocialCollectionModel(self::DISCOVER, 'Discover'),
            new SocialCollectionModel(self::GONE, self::GONE),
        ], $customFeeds);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    public function testFollowsListsAndCustomFeedsCarryTheirWebAvatars(): void
    {
        $curated = 'app.bsky.graph.defs#curatelist';
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json(['follows' => [
                ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social', 'avatar' => 'https://cdn.example/bob.jpg'],
                ['did' => 'did:plc:eve', 'handle' => 'eve.bsky.social', 'avatar' => 'javascript:alert(1)'],
            ]]),
            self::LISTS => self::json(['lists' => [[
                'uri' => 'at://did:plc:alice/app.bsky.graph.list/news',
                'name' => 'News',
                'purpose' => $curated,
                'avatar' => 'https://cdn.example/news.jpg',
            ]]]),
            self::PREFERENCES => self::savedFeeds([self::DISCOVER]),
            self::GENERATORS . self::feedsQuery([self::DISCOVER]) => self::json(['feeds' => [
                ['uri' => self::DISCOVER, 'displayName' => 'Discover', 'avatar' => 'https://cdn.example/d.jpg'],
            ]]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

        self::assertSame(
            ['https://cdn.example/bob.jpg', null],
            array_map(static fn (FollowedAccountModel $follow): ?string => $follow->avatarUrl, $candidates->follows),
        );
        self::assertSame('https://cdn.example/news.jpg', $candidates->lists[0]->avatarUrl);
        self::assertSame('https://cdn.example/d.jpg', $candidates->customFeeds[0]->avatarUrl);
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

        $customFeeds = $this->source($http)->candidates(SocialAccounts::bluesky())->customFeeds;

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

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

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

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

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

        $this->source($http)->candidates(SocialAccounts::bluesky());

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
            $this->source($http)->candidates(SocialAccounts::bluesky());
            self::fail('A refused app password must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testAFailingPublicCallIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => new MockResponse('{"lists":[]}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('Bluesky answered 503.');

        $this->source($http)->candidates(SocialAccounts::bluesky());
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

        $this->source($http)->candidates(SocialAccounts::bluesky());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => new MockResponse('', ['error' => 'Connection refused']),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates(SocialAccounts::bluesky());
    }
}
