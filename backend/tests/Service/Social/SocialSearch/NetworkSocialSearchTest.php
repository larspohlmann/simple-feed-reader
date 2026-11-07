<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\SocialSearch;

use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\SocialSearch\NetworkSocialSearch;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NetworkSocialSearchTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string BLUESKY_SEARCH = 'GET https://pds.example.test/xrpc/app.bsky.feed.searchPosts'
        . '?q=rss%20reader&sort=latest&limit=100';
    private const string MASTODON_SEARCH = 'GET https://mastodon.example/api/v2/search'
        . '?type=statuses&resolve=false&limit=40&q=rss%20reader';

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testABlueskySearchAsksForTheLatestMatchesWithTheSessionsTokenAndReadsThePosts(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)->remember(
            $account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::BLUESKY_SEARCH => self::json(['posts' => [self::blueskyPost()]])]);

        $page = $this->search($http->client())->newest($account, 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::BLUESKY_SEARCH], $http->requestLines());
        self::assertContains('Authorization: Bearer cached-jwt', $http->requests[0]['headers']);
    }

    public function testAStaleBlueskySessionIsRenewedOnceAndTheSearchRetried(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $searches = 0;
        $http = RoutedHttpClient::answering(
            static function (string $method, string $url) use (&$searches): MockResponse {
                if (self::SESSION === $method . ' ' . $url) {
                    return SocialApiWiring::blueskySessionAnswer('fresh-jwt');
                }
                ++$searches;

                return 1 === $searches
                    ? self::json(['error' => 'ExpiredToken'], Response::HTTP_BAD_REQUEST)
                    : self::json(['posts' => [self::blueskyPost()]]);
            },
        );

        $page = $this->search($http->client())->newest($account, 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::BLUESKY_SEARCH, self::SESSION, self::BLUESKY_SEARCH], $http->requestLines());
    }

    public function testAMastodonSearchAsksForTheLatestStatusesWithoutAnyLowerBound(): void
    {
        $http = new RoutedHttpClient([self::MASTODON_SEARCH => self::json(['statuses' => [self::mastodonStatus()]])]);

        $page = $this->search($http->client())->newest(SocialAccounts::mastodon(), 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::MASTODON_SEARCH], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $http->requests[0]['headers']);
    }

    public function testARefusedMastodonTokenIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([self::MASTODON_SEARCH => self::json([], Response::HTTP_UNAUTHORIZED)]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->search($http->client())->newest(SocialAccounts::mastodon(), 'rss reader');
    }

    private function search(HttpClientInterface $http): NetworkSocialSearch
    {
        return new NetworkSocialSearch(
            SocialApiWiring::blueskySessions($http, $this->cache),
            SocialApiWiring::mastodonAccountApi($http),
            SocialApiWiring::mastodonApiClient($http),
        );
    }

    /** @return array<string, mixed> */
    private static function blueskyPost(): array
    {
        return [
            'uri' => 'at://did:plc:amy/app.bsky.feed.post/1',
            'cid' => 'c1',
            'author' => ['did' => 'did:plc:amy', 'handle' => 'amy.bsky.social'],
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => 'Which RSS reader?',
                'createdAt' => '2026-11-07T10:00:00.000Z',
            ],
            'indexedAt' => '2026-11-07T10:00:01.000Z',
        ];
    }

    /** @return array<string, mixed> */
    private static function mastodonStatus(): array
    {
        return [
            'id' => '1',
            'uri' => 'https://mastodon.example/users/bob/statuses/1',
            'url' => 'https://mastodon.example/@bob/1',
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => '<p>Hello</p>',
            'in_reply_to_id' => null,
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
