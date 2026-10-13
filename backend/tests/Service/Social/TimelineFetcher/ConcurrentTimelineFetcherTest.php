<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\TimelineFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ConcurrentTimelineFetcherTest extends TestCase
{
    use AssignsEntityIds;

    private const string MASTODON = 'https://mastodon.example';
    private const string OTHER = 'https://other.example';
    private const string PDS = 'https://pds.example.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string MASTODON_HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';
    private const string BLUESKY_LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';

    private ArrayAdapter $cache;

    /** @var list<string> "METHOD url" of every request, in the order they went out */
    private array $requests = [];

    /** @var \ArrayObject<int, ResponseInterface> every response handed out, in the order they went out */
    private \ArrayObject $responses;

    private int $nextFeedId = 1;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->responses = new \ArrayObject();
    }

    public function testEveryTimelineIsOnTheWireBeforeTheFirstAnswerIsRead(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::PDS)
            ? self::blueskyTimeline()
            : self::statuses();
        $socialFeeds = [
            $this->feedOf(self::mastodonAccount(self::MASTODON, 1)),
            $this->feedOf(self::mastodonAccount(self::OTHER, 2)),
            $this->feedOf($this->blueskyAccount('jwt-1')),
        ];

        $onTheWireAtFirstAnswer = $this->onTheWireAtFirstAnswer($this->fetcher($respond), $socialFeeds);

        self::assertSame(3, $onTheWireAtFirstAnswer);
    }

    public function testAnAccountFillsItsCapAlongsideOthersBeforeTheFirstAnswerIsRead(): void
    {
        $busy = self::mastodonAccount(self::MASTODON, 1);
        $socialFeeds = [$this->feedOf($busy)];
        foreach (['1', '2', '3'] as $listId) {
            $socialFeeds[] = $this->feedOf($busy, SocialFeedKind::UserList, $listId);
        }
        $socialFeeds[] = $this->feedOf(self::mastodonAccount(self::OTHER, 2));

        $fetcher = $this->fetcher(static fn (): MockResponse => self::statuses());

        self::assertSame(4, $this->onTheWireAtFirstAnswer($fetcher, $socialFeeds), 'three of one account, one other');
    }

    public function testEveryFeedOfEveryAccountIsFetched(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::PDS)
            ? self::blueskyTimeline()
            : self::statuses();
        $socialFeeds = [
            $this->feedOf(self::mastodonAccount(self::MASTODON, 1)),
            $this->feedOf(self::mastodonAccount(self::OTHER, 2)),
            $this->feedOf($this->blueskyAccount('jwt-1')),
        ];

        $outcomes = self::read($this->fetcher($respond), $socialFeeds);

        self::assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
        }
    }

    public function testNoAccountHasMoreThanItsCapInFlightWhileAnotherRunsAlongside(): void
    {
        /** @var array<string, int> $inFlight */
        $inFlight = [];
        /** @var array<string, int> $peak */
        $peak = [];
        $respond = static function (string $method, string $url) use (&$inFlight, &$peak): MockResponse {
            $host = (string) parse_url($url, \PHP_URL_HOST);
            $inFlight[$host] = ($inFlight[$host] ?? 0) + 1;
            $peak[$host] = max($peak[$host] ?? 0, $inFlight[$host]);
            $body = (static function () use (&$inFlight, $host): \Generator {
                yield self::statusesBody();
                --$inFlight[$host];
            })();

            return new MockResponse($body);
        };
        $busy = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::OTHER, 2);
        $socialFeeds = [$this->feedOf($busy)];
        foreach (['1', '2', '3', '4'] as $listId) {
            $socialFeeds[] = $this->feedOf($busy, SocialFeedKind::UserList, $listId);
        }
        $socialFeeds[] = $this->feedOf($other);
        $socialFeeds[] = $this->feedOf($other, SocialFeedKind::UserList, '9');

        self::assertCount(7, self::read($this->fetcher($respond), $socialFeeds));
        self::assertSame(3, $peak['mastodon.example']);
        self::assertSame(2, $peak['other.example'], 'the other account runs alongside, not behind');
    }

    public function testAThrottledAccountKeepsItsUnsentFeedsBackAndLeavesOthersAlone(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::MASTODON)
            ? self::json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120'])
            : self::statuses();
        $fetcher = $this->fetcher($respond, accountConcurrency: 1);
        $throttled = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($throttled);
        $friends = $this->feedOf($throttled, SocialFeedKind::UserList, '42');
        $family = $this->feedOf($throttled, SocialFeedKind::UserList, '43');
        $other = $this->feedOf(self::mastodonAccount(self::OTHER, 2));

        $outcomes = self::read($fetcher, [$home, $friends, $family, $other]);

        self::assertSame(TimelineOutcome::Throttled, $outcomes[self::idOf($home)]->outcome);
        self::assertSame(120, $outcomes[self::idOf($home)]->retryAfterSeconds);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($friends)]->outcome);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($family)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($other)]->outcome);
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testAnInFlightSiblingOfAThrottledFeedIsSettledOnItsOwnAnswer(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::MASTODON_HOME === $method . ' ' . $url
            ? self::json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120'])
            : self::statuses();
        $account = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($account);
        $friends = $this->feedOf($account, SocialFeedKind::UserList, '42');
        $family = $this->feedOf($account, SocialFeedKind::UserList, '43');
        $parked = $this->feedOf($account, SocialFeedKind::UserList, '44');

        $outcomes = self::read($this->fetcher($respond), [$home, $friends, $family, $parked]);

        self::assertSame(TimelineOutcome::Throttled, $outcomes[self::idOf($home)]->outcome);
        foreach ([$friends, $family] as $inFlight) {
            self::assertSame('109', $outcomes[self::idOf($inFlight)]->page()->newestId);
        }
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($parked)]->outcome);
        self::assertCount(3, $this->requestsTo(self::MASTODON));
    }

    public function testARefusedTokenPausesOnlyItsAccount(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::MASTODON)
            ? self::json(['error' => 'The access token is invalid'], Response::HTTP_UNAUTHORIZED)
            : self::statuses();
        $fetcher = $this->fetcher($respond, accountConcurrency: 1);
        $refused = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::OTHER, 2);
        $home = $this->feedOf($refused);
        $friends = $this->feedOf($refused, SocialFeedKind::UserList, '42');
        $otherHome = $this->feedOf($other);

        $outcomes = self::read($fetcher, [$home, $friends, $otherHome]);

        $failure = $outcomes[self::idOf($home)]->failure();
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertSame('The Mastodon instance refused this access token.', $failure->getMessage());
        self::assertSame(TimelineOutcome::AccountPaused, $outcomes[self::idOf($friends)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($otherHome)]->outcome);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $refused->getStatus());
        self::assertSame(SocialAccountStatus::Connected, $other->getStatus());
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testAStaleBlueskySessionIsRenewedOnceAndItsFeedsAskedAgain(): void
    {
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);
        $other = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        $outcomes = self::read($this->fetcher(self::renewingStaleSessions()), [$home, $list, $other]);

        foreach ([$home, $list, $other] as $socialFeed) {
            self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($socialFeed)]->outcome);
        }
        self::assertSame([self::SESSION], $this->requestsTo('POST'));
        self::assertCount(4, $this->requestsTo('GET ' . self::PDS));
        self::assertSame(SocialAccountStatus::Connected, $bluesky->getStatus());
    }

    public function testAFeedWaitingForARenewedSessionIsNotSentOnTheStaleOne(): void
    {
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher(self::renewingStaleSessions(), accountConcurrency: 1), [$home, $list]);

        foreach ([$home, $list] as $socialFeed) {
            self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($socialFeed)]->outcome);
        }
        self::assertSame(
            [
                'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50',
                self::SESSION,
                'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50',
                'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed?list='
                    . rawurlencode(self::BLUESKY_LIST) . '&limit=50',
            ],
            $this->requests,
            'the list waits for the fresh session rather than going out on the stale one',
        );
    }

    public function testEveryAccountWithAStaleSessionIsRenewed(): void
    {
        $alice = $this->blueskyAccount('stale-jwt');
        $carol = $this->blueskyAccount('stale-jwt', 4);
        $aliceHome = $this->feedOf($alice);
        $carolHome = $this->feedOf($carol);

        $outcomes = self::read($this->fetcher(self::renewingStaleSessions()), [$aliceHome, $carolHome]);

        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($aliceHome)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($carolHome)]->outcome);
        self::assertSame([self::SESSION, self::SESSION], $this->requestsTo('POST'));
    }

    public function testAStaleAnswerToARenewedSessionRefusesTheAccount(): void
    {
        $respond = static function (string $method, string $url): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
            }

            return str_starts_with($url, self::PDS)
                ? self::json(['error' => 'ExpiredToken'], Response::HTTP_BAD_REQUEST)
                : self::statuses();
        };
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);
        $other = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        $outcomes = self::read($this->fetcher($respond), [$home, $list, $other]);

        foreach ([$home, $list] as $socialFeed) {
            $failure = $outcomes[self::idOf($socialFeed)]->failure();
            self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
            self::assertSame('Bluesky refused the session of this account.', $failure->getMessage());
        }
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($other)]->outcome);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertCount(1, $this->requestsTo(self::SESSION));
    }

    public function testASessionIsOpenedOnceForAllOfAnAccountsFeeds(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-1'])
            : self::blueskyTimeline();
        $bluesky = $this->blueskyAccount(null);
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher($respond), [$home, $list]);

        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($home)]->outcome);
        self::assertSame(TimelineOutcome::Fetched, $outcomes[self::idOf($list)]->outcome);
        self::assertSame(self::SESSION, $this->requests[0]);
        self::assertCount(1, $this->requestsTo('POST'));
    }

    public function testAThrottledSessionOpeningHoldsTheAccountsOtherFeeds(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::json(['error' => 'RateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '60'])
            : self::statuses();
        $bluesky = $this->blueskyAccount(null);
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher($respond), [$home, $list]);

        self::assertSame(TimelineOutcome::Throttled, $outcomes[self::idOf($home)]->outcome);
        self::assertSame(60, $outcomes[self::idOf($home)]->retryAfterSeconds);
        self::assertSame(TimelineOutcome::AccountThrottled, $outcomes[self::idOf($list)]->outcome);
        self::assertSame([self::SESSION], $this->requests);
    }

    public function testARefusedSessionOpeningPausesTheAccountsOtherFeeds(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::json(['error' => 'AuthenticationRequired'], Response::HTTP_UNAUTHORIZED)
            : self::statuses();
        $bluesky = $this->blueskyAccount(null);
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher($respond), [$home, $list]);

        $failure = $outcomes[self::idOf($home)]->failure();
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertSame('Bluesky refused this handle and app password.', $failure->getMessage());
        self::assertSame(TimelineOutcome::AccountPaused, $outcomes[self::idOf($list)]->outcome);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertSame([self::SESSION], $this->requests);
    }

    public function testAnAccountThatNeedsReconnectingIsNotAsked(): void
    {
        $paused = self::mastodonAccount(self::MASTODON, 1);
        $paused->markNeedsReconnect();
        $home = $this->feedOf($paused);

        $outcomes = self::read($this->fetcher(static fn (): MockResponse => self::statuses()), [$home]);

        self::assertSame(TimelineOutcome::AccountPaused, $outcomes[self::idOf($home)]->outcome);
        self::assertSame([], $this->requests);
    }

    public function testAFeedWithoutItsSocialFeedFailsWithoutAsking(): void
    {
        $orphan = self::withId(new Feed('social:mastodon:999:home'), 99);
        $fetcher = $this->fetcher(static fn (): MockResponse => self::statuses());
        $outcomes = [];

        foreach ($fetcher->fetchAll([], [99 => $orphan]) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome;
        }

        self::assertInstanceOf(PrivateFeedOrphanedException::class, $outcomes[99]->failure());
        self::assertSame([], $this->requests);
    }

    public function testAFetchedPageRecordsTheFeedsNewestStatus(): void
    {
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));

        self::read($this->fetcher(static fn (): MockResponse => self::statuses()), [$home]);

        self::assertSame('109', $home->getNewestSeenId());
    }

    public function testAFailedReadLeavesTheFeedsNewestStatusUnchanged(): void
    {
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));
        $home->recordNewestSeen('100');
        $respond = static fn (): MockResponse => new MockResponse('', ['error' => 'Connection reset by peer']);

        self::read($this->fetcher($respond), [$home]);

        self::assertSame('100', $home->getNewestSeenId());
    }

    public function testNoAnswerIsAnUnreachableNetwork(): void
    {
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));
        $respond = static fn (): MockResponse => new MockResponse('', ['error' => 'Connection reset by peer']);

        $failure = self::read($this->fetcher($respond), [$home])[self::idOf($home)]->failure();

        self::assertInstanceOf(SocialNetworkUnreachableException::class, $failure);
        self::assertSame('The Mastodon instance could not be reached.', $failure->getMessage());
    }

    public function testAbandoningTheReadSendsNothingMore(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $socialFeeds = [$this->feedOf($account)];
        foreach (['1', '2', '3'] as $listId) {
            $socialFeeds[] = $this->feedOf($account, SocialFeedKind::UserList, $listId);
        }
        $fetcher = $this->fetcher(static fn (): MockResponse => self::statuses(), accountConcurrency: 1);

        $outcomes = $fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds));
        foreach ($outcomes as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
            break;
        }

        self::assertCount(1, $this->requests);
    }

    public function testAbandoningTheReadCancelsWhatIsInFlight(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $socialFeeds = [$this->feedOf($account)];
        foreach (['1', '2'] as $listId) {
            $socialFeeds[] = $this->feedOf($account, SocialFeedKind::UserList, $listId);
        }
        $outcomes = $this->fetcher(static fn (): MockResponse => self::statuses())
            ->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds));

        foreach ($outcomes as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
            break;
        }
        unset($outcomes);

        self::assertCount(3, $this->responses);
        foreach ($this->responses as $response) {
            self::assertTrue($response->getInfo('canceled'));
        }
    }

    public function testRejectsAnAccountConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-account concurrency must be at least 1, got 0.');

        $this->fetcher(static fn (): MockResponse => self::statuses(), accountConcurrency: 0);
    }

    /** @return \Closure(string, string, list<string>): MockResponse a PDS that refuses the stale token only */
    private static function renewingStaleSessions(): \Closure
    {
        return static function (string $method, string $url, array $headers): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
            }
            if (!str_starts_with($url, self::PDS)) {
                return self::statuses();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST)
                : self::blueskyTimeline();
        };
    }

    /** @param \Closure(string, string, list<string>): MockResponse $answer by method, url and request headers */
    private function fetcher(\Closure $answer, int $accountConcurrency = 3): ConcurrentTimelineFetcher
    {
        $record = function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->requests[] = $method . ' ' . $url;
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                $headers[] = \is_string($header) ? $header : '';
            }

            return $answer($method, $url, $headers);
        };
        $client = new MockHttpClient($record);

        return SocialApiWiring::timelineFetcher($this->recording($client), $this->cache, $accountConcurrency);
    }

    private function recording(HttpClientInterface $client): HttpClientInterface
    {
        return new class ($client, $this->responses) implements HttpClientInterface {
            use DecoratorTrait;

            /** @param \ArrayObject<int, ResponseInterface> $responses */
            public function __construct(HttpClientInterface $client, private readonly \ArrayObject $responses)
            {
                $this->client = $client;
            }

            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $response = $this->client->request($method, $url, $options);
                $this->responses->append($response);

                return $response;
            }
        };
    }

    /** @param list<SocialFeed> $socialFeeds */
    private function onTheWireAtFirstAnswer(ConcurrentTimelineFetcher $fetcher, array $socialFeeds): ?int
    {
        $outcomes = $fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds));
        foreach ($outcomes as $outcome) {
            self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);

            return \count($this->requests);
        }

        return null;
    }

    /** @return list<string> */
    private function requestsTo(string $prefix): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (string $request): bool => str_contains($request, $prefix),
        ));
    }

    private function feedOf(
        SocialAccount $account,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feedId = $this->nextFeedId++;

        return new SocialFeed(
            $account,
            self::withId(new Feed(sprintf('social:test:%d', $feedId)), $feedId),
            $kind,
            $remoteId,
        );
    }

    private function blueskyAccount(?string $cachedJwt, int $accountId = 3): SocialAccount
    {
        $account = self::withId(SocialAccounts::bluesky(), $accountId);
        if (null !== $cachedJwt) {
            SocialApiWiring::blueskySessionCache($this->cache)
                ->remember($account, new BlueskySessionModel($cachedJwt, 'alice.bsky.social'));
        }

        return $account;
    }

    private static function mastodonAccount(string $instanceUrl, int $accountId): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn($instanceUrl), $accountId);
    }

    private static function idOf(SocialFeed $socialFeed): int
    {
        return $socialFeed->getFeed()->requireId();
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, TimelineOutcomeModel>
     */
    private static function read(ConcurrentTimelineFetcher $fetcher, array $socialFeeds): array
    {
        $outcomes = [];
        foreach ($fetcher->fetchAll(self::byFeedId($socialFeeds), self::feedsOf($socialFeeds)) as $feedId => $outcome) {
            $outcomes[$feedId] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, SocialFeed>
     */
    private static function byFeedId(array $socialFeeds): array
    {
        $byFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $byFeedId[self::idOf($socialFeed)] = $socialFeed;
        }

        return $byFeedId;
    }

    /**
     * @param list<SocialFeed> $socialFeeds
     *
     * @return array<int, Feed>
     */
    private static function feedsOf(array $socialFeeds): array
    {
        return array_map(
            static fn (SocialFeed $socialFeed): Feed => $socialFeed->getFeed(),
            self::byFeedId($socialFeeds),
        );
    }

    private static function statusesBody(): string
    {
        return json_encode([[
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR);
    }

    private static function statuses(): MockResponse
    {
        return new MockResponse(self::statusesBody());
    }

    private static function blueskyTimeline(): MockResponse
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
