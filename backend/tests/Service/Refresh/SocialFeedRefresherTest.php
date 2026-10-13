<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Service\Social\TimelineFetcher\TimelineFetcherInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubTimelineFetcher;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialFeedRefresherTest extends DbTestCase
{
    private MockClock $clock;
    private SocialAccount $account;
    private SocialFeed $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('social-refresher@example.test');
        $this->account = SocialAccounts::mastodonOf($user);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, PrivateFeedModel::home());
        $home = SocialFeeds::repository($this->entityManager)->findOneFor($this->account, SocialFeedKind::Home, '');
        self::assertNotNull($home);
        $this->home = $home;
    }

    public function testAFetchedPageIsStoredAsTheFeedsEntries(): void
    {
        $pass = $this->refreshed(TimelineOutcomeModel::fetched(self::page()));

        self::assertSame(1, $pass->tally->fetched());
        self::assertSame(1, $pass->tally->entriesCreated());
        self::assertSame(
            1,
            $this->entityManager->getRepository(Entry::class)->count(['feed' => $this->home->getFeed()->requireId()]),
        );
    }

    public function testAThrottledTimelineWaitsOutItsRetryAfter(): void
    {
        $pass = $this->refreshed(TimelineOutcomeModel::throttled(120));

        self::assertSame(1, $pass->tally->throttled());
        self::assertSame(0, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('2026-10-12 12:02:00', $this->home->getFeed()->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAFailedReadIsStoredOnTheFeed(): void
    {
        $unreachable = new SocialNetworkUnreachableException('The Mastodon instance answered 502.');

        $pass = $this->refreshed(TimelineOutcomeModel::failed($unreachable));

        self::assertSame(1, $pass->tally->failed());
        self::assertSame(1, $this->home->getFeed()->getConsecutiveFailures());
        $this->entityManager->clear();
        self::assertSame(
            'The Mastodon instance answered 502.',
            $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId())?->getLastErrorMessage(),
        );
    }

    public function testAFeedOfAThrottledAccountKeepsItsSchedule(): void
    {
        $nextFetchAt = $this->home->getFeed()->getNextFetchAt();

        $pass = $this->refreshed(TimelineOutcomeModel::accountThrottled());

        self::assertSame(1, $pass->tally->throttled());
        $this->assertUntouched($nextFetchAt);
    }

    public function testAFeedOfAPausedAccountKeepsItsSchedule(): void
    {
        $nextFetchAt = $this->home->getFeed()->getNextFetchAt();

        $pass = $this->refreshed(TimelineOutcomeModel::accountPaused());

        self::assertSame(1, $pass->tally->failed());
        $this->assertUntouched($nextFetchAt);
    }

    public function testTheFetcherReadsThePassesPrivateFeedsWithTheirAccounts(): void
    {
        $feed = $this->home->getFeed();
        $feedId = $feed->requireId();
        $timelines = new StubTimelineFetcher([$feed->getUrl() => TimelineOutcomeModel::throttled(null)]);

        $this->refresher($timelines)->refreshAll($this->pass(), $this->clock->now());

        self::assertSame([$feedId], array_keys($timelines->socialFeedsByFeedId));
        self::assertSame(
            $this->account->requireId(),
            $timelines->socialFeedsByFeedId[$feedId]->getSocialAccount()->requireId(),
        );
        self::assertSame([$feedId], $timelines->asked);
    }

    public function testAPassWithoutPrivateFeedsNeverStartsTheTimelineFetcher(): void
    {
        $rss = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($rss);
        $this->entityManager->flush();
        $pass = new RefreshPass([$rss], $this->clock, $this->clock->now()->getTimestamp() + 300);

        $this->refresher(self::untouchedTimelines())->refreshAll($pass, $this->clock->now());

        self::assertSame(0, $pass->tally->processed());
    }

    private function assertUntouched(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId());
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getConsecutiveFailures());
        self::assertNull($reloaded->getLastErrorMessage());
        self::assertEquals($nextFetchAt, $reloaded->getNextFetchAt());
    }

    private function refreshed(TimelineOutcomeModel $outcome): RefreshPass
    {
        $pass = $this->pass();
        $timelines = new StubTimelineFetcher([$this->home->getFeed()->getUrl() => $outcome]);
        $this->refresher($timelines)->refreshAll($pass, $this->clock->now());

        return $pass;
    }

    private function pass(): RefreshPass
    {
        return new RefreshPass([$this->home->getFeed()], $this->clock, $this->clock->now()->getTimestamp() + 300);
    }

    private function refresher(TimelineFetcherInterface $timelines): SocialFeedRefresher
    {
        return new SocialFeedRefresher(
            SocialFeeds::repository($this->entityManager),
            $timelines,
            FeedOutcomePersisters::fromContainer(self::getContainer(), $this->entityManager, $this->clock)->idle(),
        );
    }

    private static function untouchedTimelines(): TimelineFetcherInterface
    {
        return new class () implements TimelineFetcherInterface {
            public function fetchAll(array $socialFeedsByFeedId, iterable $feeds): iterable
            {
                Assert::fail('A pass without private feeds started the timeline fetcher.');
            }
        };
    }

    private static function page(): TimelinePageModel
    {
        return MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => '109',
            'uri' => 'https://mastodon.example/users/bob/statuses/109',
            'url' => 'https://mastodon.example/@bob/109',
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])]);
    }
}
