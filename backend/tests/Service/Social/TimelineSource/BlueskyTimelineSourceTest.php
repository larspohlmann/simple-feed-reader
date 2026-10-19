<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineSource\BlueskyTimelineSource;
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

final class BlueskyTimelineSourceTest extends TestCase
{
    use AssignsEntityIds;

    private const string PDS = 'https://pds.example.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string TIMELINE = 'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50';
    private const string LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string FEED = 'at://did:plc:bob/app.bsky.feed.generator/news';

    private ArrayAdapter $cache;
    private SocialAccount $account;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->account = SocialAccounts::bluesky();
        self::assignId($this->account, 3);
    }

    /** @return iterable<string, array{SocialFeedKind, string, string}> */
    public static function collections(): iterable
    {
        yield 'list' => [
            SocialFeedKind::UserList,
            self::LIST,
            'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed?list='
                . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.graph.list%2F3l1&limit=50',
        ];
        yield 'custom feed' => [
            SocialFeedKind::CustomFeed,
            self::FEED,
            'GET https://pds.example.test/xrpc/app.bsky.feed.getFeed?feed='
                . 'at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.feed.generator%2Fnews&limit=50',
        ];
    }

    public function testARequestCarriesTheSessionsTokenToTheAccountsOwnServiceOnly(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $this->rewriteCachedEntry(['pdsUrl' => 'https://elsewhere.example.test']);
        $http = new RoutedHttpClient([]);

        $request = $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Home, ''));

        self::assertSame(self::TIMELINE, $request->method . ' ' . $request->url);
        self::assertSame('Bearer cached-jwt', $request->headers['Authorization'] ?? null);
        self::assertSame([], $http->requests);
    }

    #[DataProvider('collections')]
    public function testAListOrCustomFeedIsRequestedByItsUri(SocialFeedKind $kind, string $uri, string $route): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $request = $this->source($http->client())->request($this->socialFeed($kind, $uri));

        self::assertSame($route, $request->method . ' ' . $request->url);
    }

    public function testBlueskyHasNoFollowedTags(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Tag, 'caturday'));
    }

    public function testBlueskyHasNoTrendingTimeline(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Trending, ''));
    }

    public function testAnAuthorFeedAsksForTheProfilesPostsAndSelfThreads(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);

        $request = $this->source($http->client())->request($this->socialFeed(SocialFeedKind::Author, 'did:plc:bob'));

        self::assertSame(
            'GET https://pds.example.test/xrpc/app.bsky.feed.getAuthorFeed?actor=did%3Aplc%3Abob'
                . '&filter=posts_and_author_threads&limit=50',
            $request->method . ' ' . $request->url,
        );
    }

    public function testAnAuthorFeedKeepsASelfThreadReplyTheHomeRuleWouldHide(): void
    {
        $body = ['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abd',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'And another thing', 'createdAt' => '2026-10-12T10:05:00Z'],
            ],
            'reply' => ['parent' => ['author' => ['did' => 'did:plc:bob', 'viewer' => []]]],
        ]]];

        $page = $this->source(new MockHttpClient())->pageOf(
            $this->socialFeed(SocialFeedKind::Author, 'did:plc:bob'),
            self::answer($body),
        );

        self::assertCount(1, $page->parsed->entries);
    }

    public function testRequestsForSeveralFeedsOpenOneSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1')]);
        $source = $this->source($http->client());

        $source->request($this->socialFeed(SocialFeedKind::Home, ''));
        $source->request($this->socialFeed(SocialFeedKind::UserList, self::LIST));

        self::assertSame(['POST'], array_column($http->requests, 'method'));
    }

    public function testADroppedSessionIsOpenedAfreshByTheNextRequest(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-2')]);
        $source = $this->source($http->client());

        $source->dropSession($this->account);
        $request = $source->request($this->socialFeed(SocialFeedKind::Home, ''));

        self::assertSame('Bearer jwt-2', $request->headers['Authorization'] ?? null);
        self::assertCount(1, $http->requests);
    }

    public function testAnAnswerMapsToThePage(): void
    {
        self::assertCount(1, $this->pageOf(self::answer(self::timelineBody()))->parsed->entries);
    }

    /** @return iterable<string, array{int, string}> */
    public static function staleSessions(): iterable
    {
        yield 'expired' => [Response::HTTP_BAD_REQUEST, 'ExpiredToken'];
        yield 'invalid' => [Response::HTTP_UNAUTHORIZED, 'InvalidToken'];
    }

    #[DataProvider('staleSessions')]
    public function testAStaleSessionAsksForAFreshOne(int $status, string $error): void
    {
        $this->expectException(SocialSessionStaleException::class);
        $this->expectExceptionMessage('Bluesky refused the session of this account.');

        $this->pageOf(self::answer(['error' => $error], $status));
    }

    public function testAnUnauthenticatedAnswerIsARefusedCredential(): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        $this->pageOf(self::answer(['error' => 'AuthenticationRequired'], Response::HTTP_UNAUTHORIZED));
    }

    public function testATooManyRequestsAnswerIsAThrottleCarryingItsDelay(): void
    {
        $answer = self::answer(['error' => 'RateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS, ['120']);

        try {
            $this->pageOf($answer);
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    public function testNoAnswerIsAnUnreachableBluesky(): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);
        $this->expectExceptionMessage('Bluesky could not be reached.');

        $this->pageOf(FetchOutcomeModel::failed(new FeedUnreachableException(self::PDS . ': timed out')));
    }

    /** @return iterable<string, array{int, array<string, mixed>}> */
    public static function unusableBodies(): iterable
    {
        yield 'server error' => [Response::HTTP_BAD_GATEWAY, []];
        yield 'server error that still names a feed' => [Response::HTTP_BAD_GATEWAY, ['feed' => []]];
        yield 'no feed' => [Response::HTTP_OK, ['cursor' => 'x']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('unusableBodies')]
    public function testAnUnusableAnswerReadsAsAnUnreachableNetwork(int $status, array $body): void
    {
        $this->expectException(SocialNetworkUnreachableException::class);

        $this->pageOf(self::answer($body, $status));
    }

    private function pageOf(FetchOutcomeModel $outcome): TimelinePageModel
    {
        return $this->source(new MockHttpClient())->pageOf($this->socialFeed(SocialFeedKind::Home, ''), $outcome);
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $retryAfter
     */
    private static function answer(
        array $body,
        int $status = Response::HTTP_OK,
        array $retryAfter = [],
    ): FetchOutcomeModel {
        $headers = [] === $retryAfter ? [] : ['retry-after' => $retryAfter];

        return FetchOutcomeModel::answered(
            new ApiResponseModel($status, json_encode($body, \JSON_THROW_ON_ERROR), $headers),
        );
    }

    private function source(HttpClientInterface $http): BlueskyTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, $guard));

        return new BlueskyTimelineSource(
            new BlueskySessions(
                $api,
                new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
                $this->sessionCache(),
            ),
        );
    }

    /** @param array<string, string> $fields */
    private function rewriteCachedEntry(array $fields): void
    {
        $item = $this->cache->getItem((string) array_key_first($this->cache->getValues()));
        $entry = $item->get();
        self::assertIsArray($entry);
        $this->cache->save($item->set($fields + $entry));
    }

    private function sessionCache(): BlueskySessionCache
    {
        $cipher = new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32)));

        return new BlueskySessionCache($this->cache, $cipher);
    }

    private function socialFeed(SocialFeedKind $kind, string $remoteId): SocialFeed
    {
        return new SocialFeed($this->account, new Feed('social:bluesky:3:home'), $kind, $remoteId);
    }

    private static function session(string $accessJwt): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt]);
    }

    /** @return array<string, mixed> */
    private static function timelineBody(): array
    {
        return ['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]];
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, int $status = Response::HTTP_OK, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => $headers,
        ]);
    }
}
