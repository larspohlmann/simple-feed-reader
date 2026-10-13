<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubPassFetcher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** A pass whose budget fits one feed still reaches a due private feed once it is the stalest one (#1608). */
final class RefreshRunnerStalenessTest extends DbTestCase
{
    private const string SITE = 'https://staleness.example';

    private MockClock $clock;
    private User $subscriber;
    private SocialAccount $account;
    private StubPassFetcher $passes;
    private StubFeedFetcher $homepages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->subscriber = (new UserFactory($this->entityManager, $hasher))->create('staleness@example.test');
        $this->account = SocialAccounts::mastodonOf($this->subscriber);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
        $this->passes = new StubPassFetcher($this->clock, concurrency: 1);
        $this->passes->secondsPerFetch = 100;
        $this->homepages = new StubFeedFetcher();
        $this->homepages->willReturn(
            self::SITE,
            FetchResponseModel::fetched(self::SITE, false, '<html lang="en"></html>', null, null),
        );
    }

    public function testAPrivateFeedStartsOnceItIsTheStalestDueFeedWhileRssFeedsFillEveryPass(): void
    {
        foreach (['-3 hours', '-2 hours', '-90 minutes'] as $index => $overdue) {
            $this->rssFeedDue(sprintf('%s/feed-%d.xml', self::SITE, $index), $overdue);
        }
        $privateFeedId = $this->privateFeedDue('-1 hour')->requireId();
        $this->entityManager->flush();

        $startedByPass = [];
        for ($passNumber = 1; $passNumber <= 4; ++$passNumber) {
            $report = $this->runner()->run(RefreshRequestModel::allDue(60));
            $startedByPass[] = $report->total - $report->skippedForBudget;
            $this->clock->modify('+1 day');
        }

        self::assertSame([1, 1, 1, 1], $startedByPass, 'each pass fits one feed, and every feed is due again');
        self::assertCount(3, $this->passes->fetchedUrls(), 'the three stalest RSS feeds took the first three passes');
        self::assertSame([$privateFeedId], $this->passes->asked, 'the private feed took the fourth');
    }

    private function runner(): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->build($this->passes, $this->homepages);
    }

    private function rssFeedDue(string $url, string $overdue): void
    {
        $feed = new Feed($url);
        $feed->scheduleNextFetchAt($this->clock->now()->modify($overdue));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->subscriber, $feed, $this->clock->now()));
        $this->passes->willReturn($url, FetchResponseModel::notModified($url, false, null, null));
    }

    private function privateFeedDue(string $overdue): Feed
    {
        PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock)
            ->create($this->account, PrivateFeedModel::home());
        $socialFeed = SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::Home, '');
        self::assertNotNull($socialFeed);
        $feed = $socialFeed->getFeed();
        $feed->scheduleNextFetchAt($this->clock->now()->modify($overdue));
        $this->passes->willReadTimeline($feed->getUrl(), new ThrottledTimelineModel(null));

        return $feed;
    }
}
