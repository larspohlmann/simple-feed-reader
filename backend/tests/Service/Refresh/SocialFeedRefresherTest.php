<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\SocialFeedRepository;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\SocialFeedRefresher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\MastodonTimelinePage;
use App\Tests\DbTestCase;
use App\Tests\Support\FeedOutcomePersisters;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubTimelineReader;
use App\Tests\Support\UserFactory;
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
        $home = $this->socialFeeds()->findOneFor($this->account, SocialFeedKind::Home, null);
        self::assertNotNull($home);
        $this->home = $home;
    }

    public function testAPageIsStoredAndItsNewestIdKeptForTheNextPoll(): void
    {
        $result = $this->refresher(self::page('109'))->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(1, $result->entriesCreated);
        $feedId = $this->home->getFeed()->requireId();
        $this->entityManager->clear();
        self::assertSame('109', $this->socialFeeds()->find($this->home->requireId())?->getNewestSeenId());
        self::assertSame(1, $this->entityManager->getRepository(Entry::class)->count(['feed' => $feedId]));
    }

    public function testARefusedCredentialPausesTheAccountAndFailsTheFeed(): void
    {
        $refused = new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');

        $result = $this->refresher($refused)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->entityManager->clear();
        self::assertSame(
            SocialAccountStatus::NeedsReconnect,
            $this->entityManager->find(SocialAccount::class, $this->account->requireId())?->getStatus(),
        );
        self::assertSame(
            'The Mastodon instance refused this access token.',
            $this->entityManager->find(Feed::class, $this->home->getFeed()->requireId())?->getLastErrorMessage(),
        );
    }

    public function testAnAccountAlreadyPausedIsNotAskedAgain(): void
    {
        $this->account->markNeedsReconnect();
        $this->entityManager->flush();
        $reader = new StubTimelineReader();

        $result = $this->refresherReading($reader)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame([], $reader->asked);
    }

    public function testAThrottledTimelineWaitsOutItsRetryAfter(): void
    {
        $throttled = new SocialNetworkThrottledException('The Mastodon instance asked for fewer requests.', 120);

        $result = $this->refresher($throttled)->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('2026-10-12 12:02:00', $this->home->getFeed()->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAnUnreachableNetworkBacksOffAndKeepsTheCursor(): void
    {
        $this->home->recordNewestSeen('100');
        $this->entityManager->flush();

        $result = $this->refresher(new SocialNetworkUnreachableException('The Mastodon instance answered 502.'))
            ->refresh($this->home->getFeed(), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame(1, $this->home->getFeed()->getConsecutiveFailures());
        self::assertSame('100', $this->home->getNewestSeenId());
    }

    public function testAFeedWithoutItsSocialAccountFailsWithoutAsking(): void
    {
        $orphan = new Feed('social:mastodon:999:home');
        $orphan->setSourceFormat(SourceFormat::MASTODON_API);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $reader = new StubTimelineReader();

        $result = $this->refresherReading($reader)->refresh($orphan, $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        self::assertSame('This private feed has lost its social account.', $orphan->getLastErrorMessage());
        self::assertSame([], $reader->asked);
    }

    private function refresher(TimelinePageModel|\Throwable $outcome): SocialFeedRefresher
    {
        return $this->refresherReading(new StubTimelineReader([$this->home->getFeed()->getUrl() => $outcome]));
    }

    private function refresherReading(StubTimelineReader $reader): SocialFeedRefresher
    {
        return new SocialFeedRefresher(
            $this->socialFeeds(),
            $reader,
            (new FeedOutcomePersisters(self::getContainer(), $this->entityManager, $this->clock))->idle(),
        );
    }

    private static function page(string $newestId): TimelinePageModel
    {
        return MastodonTimelinePage::of([JsonNodeModel::of([
            'id' => $newestId,
            'uri' => 'https://mastodon.example/users/bob/statuses/' . $newestId,
            'url' => 'https://mastodon.example/@bob/' . $newestId,
            'created_at' => '2026-10-12T11:59:00Z',
            'content' => '<p>Hello</p>',
            'account' => ['acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
        ])]);
    }

    private function socialFeeds(): SocialFeedRepository
    {
        /** @var SocialFeedRepository $socialFeeds */
        $socialFeeds = $this->entityManager->getRepository(SocialFeed::class);

        return $socialFeeds;
    }
}
