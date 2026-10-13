<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\PassFetcher;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\UrlGuard;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\ConcurrentPassFetcher;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Model\TimelinePageModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\CountingArrayAdapter;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ConcurrentPassFetcherTest extends TestCase
{
    use AssignsEntityIds;

    private const string MASTODON = 'https://mastodon.example';
    private const string OTHER = 'https://other.example';
    private const string PDS = 'https://pds.example.test';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string MASTODON_HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';
    private const string BLUESKY_LIST = 'at://did:plc:bob/app.bsky.graph.list/3l1';
    private const string BLUESKY_HOME = 'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50';
    private const string BLUESKY_LIST_READ = 'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed'
        . '?list=at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.graph.list%2F3l1&limit=50';
    private const string NEWS = 'https://' . SocialApiWiring::RSS_HOST;

    private CountingArrayAdapter $cache;

    private RoutedHttpClient $http;

    /** @var \ArrayObject<int, ResponseInterface> every response handed out, in the order they went out */
    private \ArrayObject $responses;

    private int $nextFeedId = 1;

    protected function setUp(): void
    {
        $this->cache = new CountingArrayAdapter();
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
            self::assertInstanceOf(FetchedTimelineModel::class, $outcome);
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

        self::assertInstanceOf(ThrottledTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame(120, self::delayOf($outcomes[self::idOf($home)]));
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($friends)]);
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($family)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($other)]);
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

        self::assertInstanceOf(ThrottledTimelineModel::class, $outcomes[self::idOf($home)]);
        foreach ([$friends, $family] as $inFlight) {
            self::assertSame('109', self::pageOf($outcomes[self::idOf($inFlight)])->newestId);
        }
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($parked)]);
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

        $failure = self::failureOf($outcomes[self::idOf($home)]);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertSame('The Mastodon instance refused this access token.', $failure->getMessage());
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($friends)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($otherHome)]);
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
            self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($socialFeed)]);
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
            self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($socialFeed)]);
        }
        self::assertSame(
            [
                'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50',
                self::SESSION,
                'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50',
                'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed?list='
                    . rawurlencode(self::BLUESKY_LIST) . '&limit=50',
            ],
            $this->http->requestLines(),
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

        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($aliceHome)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($carolHome)]);
        self::assertSame([self::SESSION, self::SESSION], $this->requestsTo('POST'));
    }

    public function testAStaleAnswerToARenewedSessionRefusesTheAccount(): void
    {
        $respond = static function (string $method, string $url): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
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
            $failure = self::failureOf($outcomes[self::idOf($socialFeed)]);
            self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
            self::assertSame('Bluesky refused the session of this account.', $failure->getMessage());
        }
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($other)]);
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

        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(self::SESSION, $this->http->requestLines()[0]);
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

        self::assertInstanceOf(ThrottledTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame(60, self::delayOf($outcomes[self::idOf($home)]));
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame([self::SESSION], $this->http->requestLines());
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

        $failure = self::failureOf($outcomes[self::idOf($home)]);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertSame('Bluesky refused this handle and app password.', $failure->getMessage());
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertSame([self::SESSION], $this->http->requestLines());
    }

    public function testAnAccountThatNeedsReconnectingIsNotAsked(): void
    {
        $paused = self::mastodonAccount(self::MASTODON, 1);
        $paused->markNeedsReconnect();
        $home = $this->feedOf($paused);

        $outcomes = self::read($this->fetcher(static fn (): MockResponse => self::statuses()), [$home]);

        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([], $this->http->requestLines());
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

        $failure = self::failureOf(self::read($this->fetcher($respond), [$home])[self::idOf($home)]);

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

        $outcomes = self::fetchAllOf($fetcher, $socialFeeds);
        foreach ($outcomes as $outcome) {
            self::assertInstanceOf(FetchedTimelineModel::class, $outcome);
            break;
        }

        self::assertCount(1, $this->http->requestLines());
    }

    public function testAbandoningTheReadCancelsWhatIsInFlight(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $socialFeeds = [$this->feedOf($account)];
        foreach (['1', '2'] as $listId) {
            $socialFeeds[] = $this->feedOf($account, SocialFeedKind::UserList, $listId);
        }
        $fetcher = $this->fetcher(static fn (): MockResponse => self::statuses());
        $outcomes = self::fetchAllOf($fetcher, $socialFeeds);

        foreach ($outcomes as $outcome) {
            self::assertInstanceOf(FetchedTimelineModel::class, $outcome);
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

    public function testRssAndPrivateTicketsAreOnTheWireBeforeTheFirstAnswerIsRead(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => match (true) {
            str_starts_with($url, self::NEWS) => self::rss(),
            str_starts_with($url, self::PDS) => self::blueskyTimeline(),
            default => self::statuses(),
        };
        $entries = [
            $this->rssFeed(self::NEWS . '/one.xml'),
            $this->feedOf(self::mastodonAccount(self::MASTODON, 1)),
            $this->rssFeed(self::NEWS . '/two.xml'),
            $this->feedOf($this->blueskyAccount('jwt-1')),
        ];

        self::assertSame(4, $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries));
    }

    public function testAFeedAndATimelineOnOneHostNeverCountAgainstEachOther(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_ends_with($url, '.rss')
            ? self::rss()
            : self::statuses();
        $first = self::mastodonAccount(self::MASTODON, 1);
        $second = self::mastodonAccount(self::MASTODON, 2);
        $entries = [
            $this->rssFeed(self::MASTODON . '/@bob.rss'),
            $this->rssFeed(self::MASTODON . '/@carol.rss'),
            $this->rssFeed(self::MASTODON . '/@dave.rss'),
            $this->feedOf($first),
            $this->feedOf($first, SocialFeedKind::UserList, '1'),
            $this->feedOf($second),
            $this->feedOf($second, SocialFeedKind::UserList, '2'),
        ];

        self::assertSame(
            6,
            $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries),
            'two feeds at the host cap and four timelines at the server cap',
        );
    }

    public function testTheServerCapHoldsAcrossAccountsWhileRssRunsAlongside(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $entries = [];
        foreach ([1, 2, 3] as $accountId) {
            $account = self::mastodonAccount(self::MASTODON, $accountId);
            $entries[] = $this->feedOf($account);
            $entries[] = $this->feedOf($account, SocialFeedKind::UserList, (string) $accountId);
        }
        $entries[] = $this->rssFeed(self::NEWS . '/one.xml');

        self::assertSame(
            5,
            $this->onTheWireAtFirstAnswer($this->fetcher($respond), $entries),
            'four timelines on one server, the feed behind them alongside',
        );
    }

    public function testAccountServerAndFeedHostCapsAllHoldThroughAMixedRun(): void
    {
        /** @var array<string, int> $inFlight */
        $inFlight = [];
        /** @var array<string, int> $peak */
        $peak = [];
        $respond = static function (string $method, string $url) use (&$inFlight, &$peak): MockResponse {
            $isFeed = str_ends_with($url, '.rss');
            $lane = ($isFeed ? 'feeds on ' : 'timelines on ') . (string) parse_url($url, \PHP_URL_HOST);
            $inFlight[$lane] = ($inFlight[$lane] ?? 0) + 1;
            $peak[$lane] = max($peak[$lane] ?? 0, $inFlight[$lane]);
            $body = (static function () use (&$inFlight, $lane, $isFeed): \Generator {
                yield $isFeed ? '<rss/>' : self::statusesBody();
                --$inFlight[$lane];
            })();

            return new MockResponse($body);
        };
        $busy = self::mastodonAccount(self::MASTODON, 1);
        $other = self::mastodonAccount(self::MASTODON, 2);
        $entries = [$this->feedOf($busy)];
        foreach (['1', '2', '3', '4'] as $listId) {
            $entries[] = $this->feedOf($busy, SocialFeedKind::UserList, $listId);
        }
        $entries[] = $this->feedOf($other);
        $entries[] = $this->feedOf($other, SocialFeedKind::UserList, '9');
        foreach (['bob', 'carol', 'dave', 'erin'] as $name) {
            $entries[] = $this->rssFeed(sprintf('%s/@%s.rss', self::MASTODON, $name));
        }

        self::assertCount(11, self::read($this->fetcher($respond), $entries));
        self::assertSame(4, $peak['timelines on mastodon.example']);
        self::assertSame(2, $peak['feeds on mastodon.example']);
    }

    public function testAThrottledAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::json(['error' => 'Too many requests'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '120']);
        $throttled = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($throttled);
        $one = $this->rssFeed(self::NEWS . '/one.xml');
        $friends = $this->feedOf($throttled, SocialFeedKind::UserList, '42');
        $two = $this->rssFeed(self::NEWS . '/two.xml');

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $one, $friends, $two]);

        self::assertInstanceOf(ThrottledTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertInstanceOf(AccountThrottledTimelineModel::class, $outcomes[self::idOf($friends)]);
        foreach ([$one, $two] as $rss) {
            self::assertSame('<rss/>', self::feedResponseOf($outcomes[$rss->requireId()])->modifiedBody());
        }
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testARefusedAccountWithholdsOnlyItsOwnFeedsWhileRssKeepsRunning(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::json(['error' => 'The access token is invalid'], Response::HTTP_UNAUTHORIZED);
        $refused = self::mastodonAccount(self::MASTODON, 1);
        $home = $this->feedOf($refused);
        $one = $this->rssFeed(self::NEWS . '/one.xml');
        $friends = $this->feedOf($refused, SocialFeedKind::UserList, '42');

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $one, $friends]);

        self::assertInstanceOf(
            SocialCredentialsRejectedException::class,
            self::failureOf($outcomes[self::idOf($home)]),
        );
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($friends)]);
        self::assertSame('<rss/>', self::feedResponseOf($outcomes[$one->requireId()])->modifiedBody());
        self::assertSame([self::MASTODON_HOME], $this->requestsTo(self::MASTODON));
    }

    public function testAStaleSessionsFeedIsSentAgainInsideTheOneRun(): void
    {
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');

        $outcomes = self::read($this->fetcher(self::renewingBesideASlowFeed()), [$home, $rss]);

        self::assertSame(
            [self::idOf($home), $rss->requireId()],
            array_keys($outcomes),
            'the renewed read lands while the RSS feed is still on the wire: no second run',
        );
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([self::SESSION], $this->requestsTo('POST'));
    }

    public function testTwoInFlightStaleReadsOfOneAccountRenewItsSessionOnceAndAreEachSentOnceMore(): void
    {
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher(self::renewingStaleSessions()), [$home, $list]);

        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(
            2,
            $this->cache->deletedItems,
            'one dropSession, plus the forget inside the one session open',
        );
        self::assertSame(
            [self::BLUESKY_HOME => 2, self::BLUESKY_LIST_READ => 2, self::SESSION => 1],
            array_count_values($this->http->requestLines()),
        );
    }

    public function testAFeedsSecondStaleAnswerPausesItsAccountAndTheGateWithholdsItsOtherFeeds(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::freshSession()
            : self::expiredToken();
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $list]);

        $failure = self::failureOf($outcomes[self::idOf($home)]);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertSame(
            [self::BLUESKY_HOME, self::SESSION, self::BLUESKY_HOME],
            $this->http->requestLines(),
            'the list never reaches the wire',
        );
    }

    public function testAFollowUpIsNeverCountedAsAStartAgainstTheBudget(): void
    {
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass([$home->getFeed(), $rss], $clock, $clock->now()->getTimestamp() + 5);

        $outcomes = [];
        $fetcher = $this->fetcher(self::renewingStaleSessions());
        foreach ($fetcher->fetchAll($pass, [self::idOf($home) => $home]) as $feedId => $outcome) {
            $outcomes[$feedId] = self::unwrapped($outcome);
        }

        self::assertSame([self::idOf($home)], $pass->startedFeedIds(), 'the budget started one feed');
        self::assertSame([self::idOf($home)], array_keys($outcomes));
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([self::BLUESKY_HOME, self::SESSION, self::BLUESKY_HOME], $this->http->requestLines());
    }

    public function testAbandoningThePassCancelsAFollowUpInFlight(): void
    {
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $fetcher = $this->fetcher(self::renewingStaleSessions());
        $outcomes = self::fetchAllOf($fetcher, [$home, $rss]);

        self::assertSame($rss->requireId(), $outcomes->key(), 'the RSS feed lands while the follow-up is on the wire');
        unset($outcomes);

        self::assertSame(
            [self::BLUESKY_HOME, 'GET ' . self::NEWS . '/one.xml', self::SESSION, self::BLUESKY_HOME],
            $this->http->requestLines(),
        );
        $followUp = $this->responses[3];
        self::assertInstanceOf(ResponseInterface::class, $followUp);
        self::assertTrue($followUp->getInfo('canceled'), 'the follow-up was cancelled on the wire');
    }

    public function testAnOrphanIsYieldedBeforeTheNextOutcomeThatLands(): void
    {
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();
        $rss = $this->rssFeed(self::NEWS . '/one.xml');

        $landings = self::landings($this->fetcher(static fn (): MockResponse => self::rss()), [$orphan, $rss]);

        self::assertSame([$orphan->requireId(), $rss->requireId()], array_column($landings, 0));
        self::assertInstanceOf(PrivateFeedOrphanedException::class, self::failureOf($landings[0][1]));
    }

    public function testAnOrphanAloneIsStillYielded(): void
    {
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();

        $landings = self::landings($this->fetcher(static fn (): MockResponse => self::rss()), [$orphan]);

        self::assertSame([$orphan->requireId()], array_column($landings, 0));
        self::assertSame([], $this->http->requestLines());
    }

    public function testEveryStartedFeedYieldsExactlyOneOutcomeWhetherOrNotItNeedsARequest(): void
    {
        $paused = self::mastodonAccount(self::OTHER, 2);
        $paused->markNeedsReconnect();
        $orphan = $this->feedOf(self::mastodonAccount(self::MASTODON, 1))->getFeed();
        $pausedHome = $this->feedOf($paused);
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 3));
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();

        $landings = self::landings($this->fetcher($respond), [$orphan, $pausedHome, $rss, $home]);
        $outcomes = array_column($landings, 1, 0);

        self::assertCount(4, $landings);
        self::assertCount(4, $outcomes);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($pausedHome)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertCount(2, $this->http->requestLines());
    }

    public function testAbandoningAMixedPassCancelsEveryRequestInFlight(): void
    {
        $account = self::mastodonAccount(self::MASTODON, 1);
        $entries = [
            $this->rssFeed(self::NEWS . '/one.xml'),
            $this->feedOf($account),
            $this->feedOf($account, SocialFeedKind::UserList, '1'),
        ];
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $fetcher = $this->fetcher($respond);
        $outcomes = self::fetchAllOf($fetcher, $entries);

        self::assertTrue($outcomes->valid());
        unset($outcomes);

        self::assertCount(3, $this->responses);
        foreach ($this->responses as $response) {
            self::assertTrue($response->getInfo('canceled'));
        }
    }

    public function testEachOutcomeComesInItsFeedsLane(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass([$rss, $home->getFeed()], $clock, $clock->now()->getTimestamp() + 300);

        $outcomes = iterator_to_array($this->fetcher($respond)->fetchAll($pass, [self::idOf($home) => $home]));

        self::assertInstanceOf(FeedPassOutcomeModel::class, $outcomes[$rss->requireId()]);
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $outcomes[self::idOf($home)]);
    }

    public function testRejectsAFeedHostConcurrencyBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Per-host concurrency must be at least 1, got 0.');

        new ConcurrentPassFetcher(
            FetchWiring::requestEngine(
                new MockHttpClient(),
                new UrlGuard(new StaticDnsResolver([]), new IpValidator()),
                FetchWiring::egressProxy(null),
            ),
            SocialApiWiring::timelineRounds(new MockHttpClient(), $this->cache),
            0,
        );
    }

    /** @return \Closure(string, string, list<string>): MockResponse a PDS that refuses the stale token only */
    private static function renewingStaleSessions(): \Closure
    {
        return static function (string $method, string $url, array $headers): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
            }
            if (str_starts_with($url, self::NEWS)) {
                return self::rss();
            }
            if (!str_starts_with($url, self::PDS)) {
                return self::statuses();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::expiredToken()
                : self::blueskyTimeline();
        };
    }

    /** @return \Closure(string, string, list<string>): MockResponse renewingStaleSessions(), its RSS answer slow */
    private static function renewingBesideASlowFeed(): \Closure
    {
        return static function (string $method, string $url, array $headers): MockResponse {
            if (str_starts_with($url, self::NEWS)) {
                return new MockResponse((static function (): \Generator {
                    yield from str_split('<rss/>' . str_repeat(' ', 14));
                })());
            }
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::expiredToken()
                : self::blueskyTimeline();
        };
    }

    private static function freshSession(): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
    }

    private static function expiredToken(): MockResponse
    {
        return self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST);
    }

    /** @param \Closure(string, string, list<string>): MockResponse $answer by method, url and request headers */
    private function fetcher(\Closure $answer, int $accountConcurrency = 3): ConcurrentPassFetcher
    {
        $this->http = RoutedHttpClient::answering($answer);

        return SocialApiWiring::passFetcher(
            $this->recording($this->http->client()),
            $this->cache,
            $accountConcurrency,
        );
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

    /** @param list<Feed|SocialFeed> $entries */
    private function onTheWireAtFirstAnswer(ConcurrentPassFetcher $fetcher, array $entries): int
    {
        self::assertTrue(self::fetchAllOf($fetcher, $entries)->valid(), 'an outcome lands');

        return \count($this->http->requests);
    }

    /** @return list<string> */
    private function requestsTo(string $prefix): array
    {
        return array_values(array_filter(
            $this->http->requestLines(),
            static fn (string $request): bool => str_contains($request, $prefix),
        ));
    }

    private function feedOf(
        SocialAccount $account,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feedId = $this->nextFeedId++;
        $feed = self::withId(new Feed(sprintf('social:test:%d', $feedId)), $feedId);
        $feed->setSourceFormat(
            SocialNetwork::Bluesky === $account->getNetwork() ? SourceFormat::BLUESKY_API : SourceFormat::MASTODON_API,
        );

        return new SocialFeed($account, $feed, $kind, $remoteId);
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
     * @param list<Feed|SocialFeed> $entries
     *
     * @return array<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> in the order they landed
     */
    private static function read(ConcurrentPassFetcher $fetcher, array $entries): array
    {
        return array_column(self::landings($fetcher, $entries), 1, 0);
    }

    /**
     * @param list<Feed|SocialFeed> $entries
     *
     * @return list<array{int|string, FetchOutcomeModel|TimelineOutcomeModelInterface}> each yield, a repeat included
     */
    private static function landings(ConcurrentPassFetcher $fetcher, array $entries): array
    {
        $landings = [];
        foreach (self::fetchAllOf($fetcher, $entries) as $feedId => $outcome) {
            $landings[] = [$feedId, $outcome];
        }

        return $landings;
    }

    private function rssFeed(string $url): Feed
    {
        $feedId = $this->nextFeedId++;

        return self::withId(new Feed($url), $feedId);
    }

    /**
     * @param list<Feed|SocialFeed> $entries in due order: an RSS feed as itself, a private feed as its social feed
     *
     * @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> each lane's outcome, unwrapped
     */
    private static function fetchAllOf(ConcurrentPassFetcher $fetcher, array $entries): \Generator
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $feeds = [];
        $socialFeedsByFeedId = [];
        foreach ($entries as $entry) {
            $feed = $entry instanceof SocialFeed ? $entry->getFeed() : $entry;
            $feeds[] = $feed;
            if ($entry instanceof SocialFeed) {
                $socialFeedsByFeedId[$feed->requireId()] = $entry;
            }
        }
        $pass = new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);

        foreach ($fetcher->fetchAll($pass, $socialFeedsByFeedId) as $feedId => $outcome) {
            yield $feedId => self::unwrapped($outcome);
        }
    }

    private static function unwrapped(
        PassOutcomeModelInterface $outcome,
    ): FetchOutcomeModel|TimelineOutcomeModelInterface {
        return match (true) {
            $outcome instanceof FeedPassOutcomeModel => $outcome->outcome,
            $outcome instanceof TimelinePassOutcomeModel => $outcome->outcome,
        };
    }

    private static function feedResponseOf(
        FetchOutcomeModel|TimelineOutcomeModelInterface $outcome,
    ): FetchResponseModel {
        self::assertInstanceOf(FetchOutcomeModel::class, $outcome);

        return $outcome->responseOrThrow();
    }

    private static function rss(): MockResponse
    {
        return new MockResponse('<rss/>');
    }

    private static function pageOf(FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): TimelinePageModel
    {
        self::assertInstanceOf(FetchedTimelineModel::class, $outcome);

        return $outcome->page;
    }

    private static function delayOf(FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): ?int
    {
        self::assertInstanceOf(ThrottledTimelineModel::class, $outcome);

        return $outcome->retryAfterSeconds;
    }

    private static function failureOf(FetchOutcomeModel|TimelineOutcomeModelInterface $outcome): \RuntimeException
    {
        self::assertInstanceOf(FailedTimelineModel::class, $outcome);

        return $outcome->failure;
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
