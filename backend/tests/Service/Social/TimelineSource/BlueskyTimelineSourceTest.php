<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineSource;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
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

    public function testTheHomeTimelineIsReadWithTheSessionsToken(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => self::timeline()]);

        $page = $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    #[DataProvider('collections')]
    public function testAListOrCustomFeedIsReadByItsUri(SocialFeedKind $kind, string $uri, string $route): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), $route => self::timeline()]);

        $page = $this->source($http->client())->page($this->socialFeed($kind, $uri));

        self::assertCount(1, $page->parsed->entries);
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

    public function testASecondPollOpensNoSecondSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => self::timeline()]);
        $source = $this->source($http->client());

        $source->page($this->socialFeed(SocialFeedKind::Home, null));
        $source->page($this->socialFeed(SocialFeedKind::Home, null));

        $sessionOpenings = array_filter(
            $http->requests,
            static fn (array $request): bool => 'POST' === $request['method'],
        );
        self::assertCount(1, $sessionOpenings);
    }

    public function testAnExpiredCachedSessionIsReplacedOnceAndThePollSucceeds(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $sessionAnswer = self::session('jwt-2');
        $timelineAnswer = self::timeline();
        $http = new MockHttpClient([
            self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST),
            $sessionAnswer,
            $timelineAnswer,
        ]);

        $page = $this->source($http)->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertCount(1, $page->parsed->entries);
        self::assertSame('POST', $sessionAnswer->getRequestMethod());
        $headers = $timelineAnswer->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer jwt-2', $headers);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testATokenRefusedAgainAfterAFreshSessionIsARefusedCredential(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new MockHttpClient([
            self::json(['error' => 'InvalidToken'], Response::HTTP_UNAUTHORIZED),
            self::session('jwt-2'),
            self::json(['error' => 'InvalidToken'], Response::HTTP_UNAUTHORIZED),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->page($this->socialFeed(SocialFeedKind::Home, null));
    }

    public function testACachedSessionRefusedAsUnauthenticatedIsARefusedCredentialWithoutANewSession(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([
            self::TIMELINE => self::json(['error' => 'AuthenticationRequired'], Response::HTTP_UNAUTHORIZED),
        ]);

        try {
            $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));
            self::fail('A refused token must not read as a page.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(['GET'], array_column($http->requests, 'method'));
        }
    }

    public function testASecondExpiredTokenAfterAFreshSessionIsARefusedCredential(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $expired = ['error' => 'ExpiredToken'];
        $http = new MockHttpClient([
            self::json($expired, Response::HTTP_BAD_REQUEST),
            self::session('jwt-2'),
            self::json($expired, Response::HTTP_BAD_REQUEST),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->page($this->socialFeed(SocialFeedKind::Home, null));
    }

    public function testACachedSessionsTokenGoesOnlyToTheAccountsOwnService(): void
    {
        $this->sessionCache()->remember(
            $this->account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $this->rewriteCachedEntry(['pdsUrl' => 'https://elsewhere.example.test']);
        $http = new RoutedHttpClient([self::TIMELINE => self::timeline()]);

        $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));

        self::assertSame([self::PDS . '/xrpc/app.bsky.feed.getTimeline?limit=50'], $http->requestedUrls());
    }

    public function testTooManyRequestsIsAThrottleCarryingItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => self::session('jwt-1'),
            self::TIMELINE => self::json(
                ['error' => 'RateLimitExceeded'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => '120'],
            ),
        ]);

        try {
            $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));
            self::fail('A 429 must not read as a page.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsAnUnreachableNetwork(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::session('jwt-1'), self::TIMELINE => $answer]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http->client())->page($this->socialFeed(SocialFeedKind::Home, null));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'server error' => [self::json([], Response::HTTP_BAD_GATEWAY)];
        yield 'no feed' => [self::json(['cursor' => 'x'])];
    }

    private function source(HttpClientInterface $http): BlueskyTimelineSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, $guard));

        return new BlueskyTimelineSource(
            $api,
            new BlueskySessions(
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

    private function socialFeed(SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        return new SocialFeed($this->account, new Feed('social:bluesky:3:home'), $kind, $remoteId);
    }

    private static function session(string $accessJwt): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt]);
    }

    private static function timeline(): MockResponse
    {
        return self::json(['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]]);
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
