<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Content\Comments\Exception\CommentsThrottledException;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Foundation\RateLimit\RateLimitGuard;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyAccountThreads;
use App\Service\Social\Social\LemmyApiClient;
use App\Service\Social\Social\LemmyPosts;
use App\Service\Social\Social\StoredCredential;
use App\Tests\DbTestCase;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StaticDnsResolver;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class LemmyAccountThreadsTest extends DbTestCase
{
    use SeedsUsers;

    private const string FIXTURES = __DIR__ . '/../../../Fixtures/lemmy/';
    private const string FEDERATED_POST = 'GET https://feddit.example/api/v3/post?id=7';
    private const string RESOLVE = 'GET https://lemmy.example/api/v3/resolve_object'
        . '?q=https%3A%2F%2Forigin.example%2Fpost%2F3';
    private const string THREAD = 'GET https://lemmy.example/api/v3/comment/list?post_id=990'
        . '&sort=Hot&type_=All&max_depth=8&limit=50';

    /** Must match framework.rate_limiter.social_replies.limit in rate_limiter.yaml. */
    private const int REPLIES_BUDGET = 120;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::lemmyOf($this->user('lemmy-threads@example.test')),
        );
    }

    public function testTheReaderIsTheirReadyLemmyAccount(): void
    {
        self::assertSame($this->account, $this->threads($this->federated())->readerFor($this->account->getUser()));
    }

    public function testAnAccountThatNeedsReconnectingReadsNothing(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();

        self::assertNull($this->threads($this->federated())->readerFor($this->account->getUser()));
    }

    public function testThreadsAreReadByTheLocalPostId(): void
    {
        $http = $this->federated();

        $body = $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://feddit.example', 7));

        self::assertSame([self::FEDERATED_POST, self::RESOLVE, self::THREAD], $http->requestLines());
        self::assertContains('Authorization: Bearer ' . SocialAccounts::LEMMY_JWT, $http->requests[2]['headers']);
        self::assertSame([], self::authorizationOf($http->requests[0]['headers']));
        self::assertNotSame([], self::authorizationOf($http->requests[1]['headers']));
        self::assertSame(file_get_contents(self::FIXTURES . 'comment-list-as-account.json'), $body);
    }

    public function testARefusedSessionIsUnavailableAndMarksTheAccount(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '',
            ['http_code' => Response::HTTP_UNAUTHORIZED],
        ));

        try {
            $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://lemmy.example', 990));
            self::fail('A refused session must not read as a thread.');
        } catch (CommentsUnavailableException) {
            self::assertSame(SocialAccountStatus::NeedsReconnect, $this->account->getStatus());
        }
    }

    public function testAThrottledInstanceIsThrottled(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '30'],
        ]));

        try {
            $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://lemmy.example', 990));
            self::fail('A 429 must not read as a thread.');
        } catch (CommentsThrottledException $exception) {
            self::assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function testAThrottledInstanceWithoutADelayIsAskedAgainInAMinute(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '{}',
            ['http_code' => Response::HTTP_TOO_MANY_REQUESTS],
        ));

        try {
            $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://lemmy.example', 990));
            self::fail('A 429 must not read as a thread.');
        } catch (CommentsThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testAPostTheInstanceCannotFindIsUnavailable(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '{"error": "couldnt_find_post"}',
            ['http_code' => Response::HTTP_BAD_REQUEST],
        ));
        $this->expectException(CommentsUnavailableException::class);

        $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://lemmy.example', 990));
    }

    public function testAnUnreachableInstanceIsUnavailable(): void
    {
        $http = RoutedHttpClient::answering(static fn (): MockResponse => new MockResponse(
            '',
            ['http_code' => Response::HTTP_BAD_GATEWAY],
        ));
        $this->expectException(CommentsUnavailableException::class);

        $this->threads($http)->threadAs($this->account, new LemmyPostModel('https://lemmy.example', 990));
    }

    public function testAnExhaustedRepliesBudgetIsUnavailableAfterOneUnitPerFetch(): void
    {
        $http = RoutedHttpClient::answering(static fn (string $method, string $url): MockResponse => new MockResponse(
            str_contains($url, '/post?') ? '{"post_view": {"post": {"id": 990}}}' : '{"comments": []}',
        ));
        $threads = $this->threads($http);
        $post = new LemmyPostModel('https://lemmy.example', 990);
        for ($fetch = 0; $fetch < self::REPLIES_BUDGET; ++$fetch) {
            $threads->threadAs($this->account, $post);
        }
        $this->expectException(CommentsUnavailableException::class);

        $threads->threadAs($this->account, $post);
    }

    private function federated(): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::FEDERATED_POST => new MockResponse(
                (string) file_get_contents(self::FIXTURES . 'post-anonymous.json'),
            ),
            self::RESOLVE => new MockResponse((string) file_get_contents(self::FIXTURES . 'resolve-post.json')),
            self::THREAD => new MockResponse(
                (string) file_get_contents(self::FIXTURES . 'comment-list-as-account.json'),
            ),
        ]);
    }

    private function threads(RoutedHttpClient $http): LemmyAccountThreads
    {
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'lemmy.example' => ['93.184.216.34'],
                'feddit.example' => ['93.184.216.35'],
            ]),
            new IpValidator(),
        );
        $client = new LemmyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));
        $api = new LemmyAccountApi($client, new StoredCredential(SocialAccounts::cipher()));
        $limiter = self::getContainer()->get('limiter.social_replies');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return new LemmyAccountThreads(
            $this->entityManager->getRepository(SocialAccount::class),
            new ActingAccountGuard($this->entityManager),
            new LemmyPosts($api, $client),
            $api,
            new RateLimitGuard(new MockClock('2026-11-14 12:00:00')),
            $limiter,
        );
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private static function authorizationOf(array $headers): array
    {
        return array_values(array_filter(
            $headers,
            static fn (string $header): bool => str_starts_with(mb_strtolower($header), 'authorization:'),
        ));
    }
}
