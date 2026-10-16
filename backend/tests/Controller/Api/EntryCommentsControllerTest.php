<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\DnsResolver\NativeDnsResolver;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\StubFeedFetcher;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class EntryCommentsControllerTest extends ApiTestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/';
    private const string FEED = self::THREAD . '.rss';
    private const string POST_URL = 'https://mastodon.social/@Gargron/110';
    private const string PUBLIC_CONTEXT = 'GET https://mastodon.social/api/v1/statuses/110/context';

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        foreach (['host_throttle.cache', 'comments.cache'] as $poolId) {
            $pool = self::getContainer()->get($poolId);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }
        self::ensureKernelShutdown();
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function seedEntry(User $user, Discussion $discussion): Entry
    {
        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $feed->setTitle('Seeded');
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            't3_1woq4he',
            null,
            'Stack',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setAuthor(new EntryAuthor('/u/Background_Lie11'));
        $entry->setDiscussion($discussion);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private static function redditThread(): Discussion
    {
        return Discussion::withCommentsFeed(self::THREAD, self::FEED, CommentsLoad::Auto);
    }

    private function installFetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        self::getContainer()->set(FeedFetcherInterface::class, $fetcher);

        return $fetcher;
    }

    private function serveRedditThread(StubFeedFetcher $fetcher): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/reddit/thread-comments.atom');
        $fetcher->willReturn(self::FEED, FetchResponseModel::fetched(self::FEED, false, $xml, null, null));
    }

    public function testOkReturnsComments(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-ok@example.com');
        $this->serveRedditThread($this->installFetcher());
        $entry = $this->seedEntry($user, self::redditThread());

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame(['status', 'comments', 'omitted', 'fetchedAt'], array_keys($body));
        self::assertSame('ok', $body['status']);
        self::assertIsArray($body['comments']);
        self::assertNotSame([], $body['comments']);
        $firstComment = $body['comments'][0];
        self::assertIsArray($firstComment);
        self::assertSame(
            [
                'author', 'authorHandle', 'authorUrl', 'authorAvatarUrl', 'url', 'publishedAt', 'html',
                'byEntryAuthor', 'depth', 'media', 'linkCard', 'quote',
            ],
            array_keys($firstComment),
        );
        self::assertIsString($firstComment['publishedAt']);
    }

    public function testThrottledCarriesRetryAfter(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-throttled@example.com');
        $fetcher = $this->installFetcher();
        $fetcher->willThrow(self::FEED, new FeedThrottledException('429', 90));
        $entry = $this->seedEntry($user, self::redditThread());

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'throttled', 'retryAfter' => 90], $this->payload($client));
    }

    public function testAFetchFailureIsFailed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-failed@example.com');
        $fetcher = $this->installFetcher();
        $fetcher->willThrow(self::FEED, new FeedUnreachableException('HTTP 500', statusCode: 500));
        $entry = $this->seedEntry($user, self::redditThread());

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'failed'], $this->payload($client));
    }

    public function testTheThirtyFirstRequestInFiveMinutesIsRateLimitedBeforeAnyFetch(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('comments-limited@example.com');
        $fetcher = $this->installFetcher();
        $fetcher->willThrow(self::FEED, new FeedUnreachableException('HTTP 500', statusCode: 500));
        $entry = $this->seedEntry($user, self::redditThread());
        $url = '/api/entries/' . $entry->getId() . '/comments';

        for ($request = 0; $request < 30; ++$request) {
            $client->request('GET', $url, server: $headers);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', $url, server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertCount(30, $fetcher->fetchedUrls);
    }

    public function testASecondOpenIsAnsweredFromTheCache(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('comments-cached@example.com');
        $fetcher = $this->installFetcher();
        $this->serveRedditThread($fetcher);
        $url = '/api/entries/' . $this->seedEntry($user, self::redditThread())->getId() . '/comments';

        $client->request('GET', $url, server: $headers);
        $first = $this->payload($client);
        $client->request('GET', $url, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame($first, $this->payload($client));
        self::assertCount(1, $fetcher->fetchedUrls);
    }

    public function testRefreshFetchesAgain(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('comments-refresh@example.com');
        $fetcher = $this->installFetcher();
        $this->serveRedditThread($fetcher);
        $url = '/api/entries/' . $this->seedEntry($user, self::redditThread())->getId() . '/comments';

        $client->request('GET', $url, server: $headers);
        $client->request('GET', $url . '?refresh=1', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $this->payload($client)['status']);
        self::assertCount(2, $fetcher->fetchedUrls);
    }

    public function testCachedOpensSpendNoBudget(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('comments-budget@example.com');
        $fetcher = $this->installFetcher();
        $this->serveRedditThread($fetcher);
        $url = '/api/entries/' . $this->seedEntry($user, self::redditThread())->getId() . '/comments';

        for ($request = 0; $request < 40; ++$request) {
            $client->request('GET', $url, server: $headers);
            self::assertResponseIsSuccessful();
        }

        self::assertCount(1, $fetcher->fetchedUrls);
    }

    public function testEntryWithoutCommentsFeedIs404(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-nofeed@example.com');
        $this->installFetcher();
        $entry = $this->seedEntry($user, Discussion::of(self::THREAD, null, CommentsLoad::Auto));

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testSomeoneElsesEntryIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('comments-idor@example.com');
        [, $stranger] = $this->auth('comments-owner@example.com');
        $this->installFetcher();
        $entry = $this->seedEntry($stranger, self::redditThread());

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testAMastodonPostsRepliesComeFromItsOriginWithoutAnAccount(): void
    {
        [$client, $http] = $this->clientRouting([self::PUBLIC_CONTEXT => self::mastodonContext()]);
        [$headers, $user] = $this->auth('comments-mastodon@example.com');

        $client->request('GET', '/api/entries/' . $this->mastodonPostFor($user) . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame('ok', $body['status']);
        self::assertIsArray($body['comments']);
        self::assertSame([0, 0, 1], array_column($body['comments'], 'depth'));
        self::assertSame(
            ['@cy@fosstodon.org', '@ann@hachyderm.io', '@Gargron@mastodon.social'],
            array_column($body['comments'], 'authorHandle'),
        );
        self::assertSame([0, 1, 0], array_map(
            static fn (mixed $media): int => \is_array($media) ? \count($media) : -1,
            array_column($body['comments'], 'media'),
        ));
        self::assertSame([self::PUBLIC_CONTEXT], $http->requestLines());
    }

    public function testASocialPostsRepliesAreCachedAndRefreshable(): void
    {
        [$client, $http] = $this->clientRouting([self::PUBLIC_CONTEXT => self::mastodonContext()]);
        [$headers, $user] = $this->auth('comments-mastodon-cached@example.com');
        $url = '/api/entries/' . $this->mastodonPostFor($user) . '/comments';

        $client->request('GET', $url, server: $headers);
        $first = $this->payload($client);
        $client->request('GET', $url, server: $headers);
        $cached = $this->payload($client);
        $client->request('GET', $url . '?refresh=1', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame($first, $cached);
        self::assertSame('ok', $this->payload($client)['status']);
        self::assertSame([self::PUBLIC_CONTEXT, self::PUBLIC_CONTEXT], $http->requestLines());
    }

    /**
     * @param array<string, MockResponse> $routes
     *
     * @return array{0: KernelBrowser, 1: RoutedHttpClient}
     */
    private function clientRouting(array $routes): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $http = new RoutedHttpClient($routes);
        $container = self::getContainer();
        $container->set('http_client.uri_template', $http->client());
        $container->set(NativeDnsResolver::class, new StaticDnsResolver(['mastodon.social' => ['93.184.216.34']]));

        return [$client, $http];
    }

    private function mastodonPostFor(User $user): int
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager());
        $subscription = $fixtures->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);

        return $fixtures->post($subscription, self::POST_URL, '2026-10-16T08:00:00Z')->requireId();
    }

    private static function mastodonContext(): MockResponse
    {
        return new MockResponse(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/social/mastodon-context.json'),
        );
    }

    public function testUnauthenticatedIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries/1/comments');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
