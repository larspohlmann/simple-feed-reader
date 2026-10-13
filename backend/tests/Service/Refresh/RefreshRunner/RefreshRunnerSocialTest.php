<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Social\Model\PrivateFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The real pass fetcher behind RefreshRunner, RSS and private feeds alike, over a routed HTTP client. */
final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://news.example/feed.xml';
    private const string RSS = 'GET https://news.example/feed.xml';
    private const string HOME = 'GET https://mastodon.example/api/v1/timelines/home?limit=40';
    private const string FRIENDS = 'GET https://mastodon.example/api/v1/timelines/list/42?limit=40';
    private const string FAMILY = 'GET https://mastodon.example/api/v1/timelines/list/43?limit=40';

    private MockClock $clock;
    private User $user;
    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('refresh-social@example.test');
        $this->account = SocialAccounts::mastodonOf($this->user);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    public function testAPrivateFeedIsReadThroughItsAccountAndNeverFetchedByUrl(): void
    {
        $this->subscribeToRss();
        $homeId = $this->privateFeed(PrivateFeedModel::home())->requireId();
        $http = new RoutedHttpClient([self::RSS => self::rssBody(), self::HOME => self::statuses()]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertEqualsCanonicalizing([self::RSS, self::HOME], $http->requestLines());
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $homeId]));
        $this->entityManager->clear();
        $stored = SocialFeeds::repository($this->entityManager)->findWithAccountByFeedIds([$homeId])[$homeId] ?? null;
        self::assertSame('109', $stored?->getNewestSeenId());
    }

    public function testAThrottledAccountIsAskedOnceAPass(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::throttled(), self::FRIENDS => self::throttled()]);

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->throttled);
        self::assertCount(1, $http->requests);
    }

    public function testARefusedTokenPausesTheAccountForGoodAndSparesItsOtherFeeds(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::refused(), self::FRIENDS => self::refused()]);

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        $this->entityManager->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager->find(SocialAccount::class, $this->account->requireId())?->getStatus(),
        );
        self::assertSame(2, $report->failed);
        self::assertCount(1, $http->requests);
    }

    public function testTheBudgetGateStopsThePassMidway(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::statuses(), self::FRIENDS => self::statuses()]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(5));

        self::assertSame('partial', $report->status);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame(1, $report->fetched);
        self::assertCount(1, $http->requests);
    }

    public function testAStoreThatAbortsTheRunStopsReadingTimelines(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $this->privateFeed(PrivateFeedModel::userList('43', 'Family'));
        $http = new RoutedHttpClient([
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
            self::FAMILY => self::statuses(),
        ]);
        $failing = new FlushFailingEntityManager(
            $this->entityManager,
            failingFlush: 2,
            thrown: DuplicateKeyViolation::exception(),
        );

        $report = $this->runner($http, accountConcurrency: 1, flushing: $failing)
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(2, $report->remaining, 'the feed whose flush failed is still due, as is the unasked one');
        self::assertCount(2, $http->requests, 'the third timeline is never asked once the run aborted');
    }

    public function testAMixedPassReadsFeedsAndTimelinesInOneRunAndLeavesNothingRemaining(): void
    {
        $this->subscribeToRss();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
        ]);

        $report = $this->runner($http)->run(RefreshRequestModel::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(3, $report->fetched);
        self::assertSame(0, $report->remaining);
    }

    public function testAThrottledAccountsWithheldFeedIsTalliedSoNothingStaysRemaining(): void
    {
        $this->subscribeToRss();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::throttled(),
            self::FRIENDS => self::throttled(),
        ]);

        $report = $this->runner($http, accountConcurrency: 1)->run(RefreshRequestModel::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(1, $report->fetched);
        self::assertSame(2, $report->throttled);
        self::assertSame(0, $report->remaining);
        self::assertEqualsCanonicalizing([self::RSS, self::HOME], $http->requestLines());
    }

    public function testAnAbortInAMixedPassLeavesEveryUnprocessedFeedRemaining(): void
    {
        $this->subscribeToRss();
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([
            self::RSS => self::rssBody(),
            self::HOME => self::statuses(),
            self::FRIENDS => self::statuses(),
        ]);
        $failing = new FlushFailingEntityManager(
            $this->entityManager,
            failingFlush: 2,
            thrown: DuplicateKeyViolation::exception(),
        );

        $report = $this->runner($http, flushing: $failing)->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(2, $report->remaining, 'three due, one stored before the failing one');
    }

    private function runner(
        RoutedHttpClient $http,
        int $accountConcurrency = 3,
        ?EntityManagerInterface $flushing = null,
    ): RefreshRunner {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->flushingThrough($flushing ?? $this->entityManager)
            ->build(
                SocialApiWiring::passFetcher($http->client(), new ArrayAdapter(), $accountConcurrency),
                $this->homepages(),
            );
    }

    private function subscribeToRss(): void
    {
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($this->user, $rss, $this->clock->now()));
        $this->entityManager->flush();
    }

    private function privateFeed(PrivateFeedModel $privateFeed): Feed
    {
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, $privateFeed);
        $socialFeed = SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, $privateFeed->kind, $privateFeed->remoteId);
        self::assertNotNull($socialFeed);

        return $socialFeed->getFeed();
    }

    private function homepages(): StubFeedFetcher
    {
        $homepages = new StubFeedFetcher();
        foreach (['https://news.example', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn(
                $origin,
                FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
            );
        }

        return $homepages;
    }

    private static function rssBody(): MockResponse
    {
        return new MockResponse(/** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://news.example/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
    }

    private static function statuses(): MockResponse
    {
        return new MockResponse(json_encode([[
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ]], \JSON_THROW_ON_ERROR));
    }

    private static function throttled(): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '120'],
        ]);
    }

    private static function refused(): MockResponse
    {
        return new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
    }
}
