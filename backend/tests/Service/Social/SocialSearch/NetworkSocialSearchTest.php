<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\SocialSearch;

use App\Enum\SocialAccountStatus;
use App\Service\Fetch\AnswerSettler\PassThroughSettler;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\SocialSearch\NetworkSocialSearch;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use Doctrine\ORM\EntityManagerInterface;
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

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
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

        $page = $this->search($http->client())->newest($account, 'rss reader');

        self::assertSame(
            ['at://did:plc:amy/app.bsky.feed.post/1'],
            array_map(static fn (ParsedEntryModel $entry): string => $entry->guid, $page->parsed->entries),
        );
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

    public function testARefusedMastodonTokenMarksTheAccountForReconnecting(): void
    {
        $http = new RoutedHttpClient([self::MASTODON_SEARCH => self::json([], Response::HTTP_UNAUTHORIZED)]);
        $account = SocialAccounts::mastodon();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $this->entityManager = $entityManager;

        try {
            $this->search($http->client())->newest($account, 'rss reader');
            self::fail('A refused token must be a refused credential.');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $account->getStatus());
        }
    }

    public function testAMastodonPreviewAsksOncePerAlternativeAndMergesTheAnswers(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('1')]]),
            $atom => self::json(['statuses' => [
                self::mastodonStatus('1'),
                self::mastodonStatus('2', '<p>atom feeds</p>'),
            ]]),
        ]);

        $page = $this->search($http->client())->newest(SocialAccounts::mastodon(), '(rss || atom)');

        self::assertCount(2, $page->parsed->entries);
        self::assertSame([$rss, $atom], $http->requestLines());
    }

    public function testAMastodonPreviewListsTheMergedAnswersNewestFirst(): void
    {
        $rss = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=rss';
        $atom = 'GET https://mastodon.example/api/v2/search?type=statuses&resolve=false&limit=40&q=atom';
        $http = new RoutedHttpClient([
            $rss => self::json(['statuses' => [self::mastodonStatus('110'), self::mastodonStatus('108')]]),
            $atom => self::json(['statuses' => [
                self::mastodonStatus('111', '<p>atom feeds</p>'),
                self::mastodonStatus('109', '<p>atom feeds</p>'),
            ]]),
        ]);

        $page = $this->search($http->client())->newest(SocialAccounts::mastodon(), '(rss || atom)');

        self::assertSame(
            [
                'https://mastodon.example/@bob/111',
                'https://mastodon.example/@bob/110',
                'https://mastodon.example/@bob/109',
                'https://mastodon.example/@bob/108',
            ],
            array_map(static fn (ParsedEntryModel $entry): ?string => $entry->url, $page->parsed->entries),
        );
    }

    public function testAMastodonPreviewTooBroadIsRefusedBeforeAnyRequest(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->search($http->client())->newest(SocialAccounts::mastodon(), '(a || b || c) (d || e || f)');
            self::fail('Nine alternatives are more than Mastodon is asked for.');
        } catch (SearchQueryTooBroadException) {
            self::assertSame([], $http->requestLines());
        }
    }

    private function search(HttpClientInterface $http): NetworkSocialSearch
    {
        return new NetworkSocialSearch(
            SocialApiWiring::blueskySessions($http, $this->cache),
            SocialApiWiring::mastodonAccountApi($http),
            SocialApiWiring::requestEngine($http),
            new PassThroughSettler(),
            new ActingAccountGuard($this->entityManager),
        );
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

    /** @return array<string, mixed> */
    private static function mastodonStatus(string $id = '1', string $content = '<p>Which RSS reader?</p>'): array
    {
        return [
            'id' => $id,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $id,
            'url' => 'https://mastodon.example/@bob/' . $id,
            'created_at' => '2026-10-12T10:00:00Z',
            'content' => $content,
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
