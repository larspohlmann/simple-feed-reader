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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The real concurrent timeline fetcher behind RefreshRunner, over a routed HTTP client. */
final class RefreshRunnerSocialTest extends DbTestCase
{
    private const string RSS_URL = 'https://example.com/feed.xml';
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
        $rss = new Feed(self::RSS_URL);
        $this->entityManager->persist($rss);
        $this->entityManager->persist(new Subscription($this->user, $rss, $this->clock->now()));
        $this->entityManager->flush();
        $homeId = $this->privateFeed(PrivateFeedModel::home())->requireId();
        $feeds = new StubFeedFetcher($this->clock);
        $feeds->willReturnBody(self::RSS_URL, /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Example</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>p-1</guid></item>
            </channel></rss>
            XML);
        $http = new RoutedHttpClient([self::HOME => self::statuses()]);

        $report = $this->runners($http)->build($feeds, $this->homepages())->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->fetched);
        self::assertSame([self::RSS_URL], $feeds->fetchedUrls);
        self::assertCount(1, $http->requests);
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

        $report = $this->runners($http, accountConcurrency: 1)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame(2, $report->throttled);
        self::assertCount(1, $http->requests);
    }

    public function testARefusedTokenPausesTheAccountForGoodAndSparesItsOtherFeeds(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::refused(), self::FRIENDS => self::refused()]);

        $report = $this->runners($http, accountConcurrency: 1)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(300));

        $this->entityManager->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager->find(SocialAccount::class, $this->account->requireId())?->getStatus(),
        );
        self::assertSame(2, $report->failed);
        self::assertCount(1, $http->requests);
    }

    public function testTheBudgetGateStopsTheSocialWaveMidPass(): void
    {
        $this->privateFeed(PrivateFeedModel::home());
        $this->privateFeed(PrivateFeedModel::userList('42', 'Friends'));
        $http = new RoutedHttpClient([self::HOME => self::statuses(), self::FRIENDS => self::statuses()]);

        $report = $this->runners($http)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(5));

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

        $report = $this->runners($http, accountConcurrency: 1)
            ->flushingThrough($failing)
            ->build(new StubFeedFetcher($this->clock), $this->homepages())
            ->run(RefreshRequestModel::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertCount(2, $http->requests, 'the third timeline is never asked once the run aborted');
    }

    private function runners(RoutedHttpClient $http, int $accountConcurrency = 3): RefreshRunners
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->readingTimelinesFrom(
                SocialApiWiring::timelineFetcher($http->client(), new ArrayAdapter(), $accountConcurrency),
            );
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
        foreach (['https://example.com', 'https://mastodon.example'] as $origin) {
            $homepages->willReturn(
                $origin,
                FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
            );
        }

        return $homepages;
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
