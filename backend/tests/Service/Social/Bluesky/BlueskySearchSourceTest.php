<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Bluesky\Model\BlueskySessionModel;
use App\Service\Social\Bluesky\BlueskySearchSource;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BlueskySearchSourceTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string BLUESKY_SEARCH = 'GET https://pds.example.test/xrpc/app.bsky.feed.searchPosts'
        . '?q=rss%20reader&sort=latest&limit=100';

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testASearchAsksForTheLatestMatchesWithTheSessionsTokenAndReadsThePosts(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)->remember(
            $account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::BLUESKY_SEARCH => self::json(['posts' => [self::blueskyPost()]])]);

        $page = $this->source($http->client())->fetchNewest($account, 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::BLUESKY_SEARCH], $http->requestLines());
        self::assertContains('Authorization: Bearer cached-jwt', $http->requests[0]['headers']);
    }

    public function testAPreviewHoldsOnlyThePostsWhoseOwnTextMatches(): void
    {
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)->remember(
            $account,
            new BlueskySessionModel('cached-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::BLUESKY_SEARCH => self::json(['posts' => [
            self::blueskyPost(),
            self::blueskyPost('2', 'A link worth reading'),
        ]])]);

        $page = $this->source($http->client())->fetchNewest($account, 'rss reader');

        self::assertSame(
            ['at://did:plc:amy/app.bsky.feed.post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
    }

    public function testAStaleSessionIsRenewedOnceAndTheSearchRetried(): void
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

        $page = $this->source($http->client())->fetchNewest($account, 'rss reader');

        self::assertCount(1, $page->parsed->entries);
        self::assertSame([self::BLUESKY_SEARCH, self::SESSION, self::BLUESKY_SEARCH], $http->requestLines());
    }

    private function source(HttpClientInterface $http): BlueskySearchSource
    {
        return new BlueskySearchSource(SocialApiWiring::blueskySessions($http, $this->cache));
    }

    /** @return array<string, mixed> */
    private static function blueskyPost(string $postId = '1', string $text = 'Which RSS reader?'): array
    {
        return [
            'uri' => 'at://did:plc:amy/app.bsky.feed.post/' . $postId,
            'cid' => 'c1',
            'author' => ['did' => 'did:plc:amy', 'handle' => 'amy.bsky.social'],
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => '2026-11-07T10:00:00.000Z',
            ],
            'indexedAt' => '2026-11-07T10:00:01.000Z',
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, int $status = Response::HTTP_OK): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
