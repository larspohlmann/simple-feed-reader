<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\QueuedLinkPosts;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\ShareLinkCardsWiring;
use App\Tests\Support\StubGuardedImageDownloader;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubLokiEndpoint;
use App\Tests\Support\StubPassFetcher;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfileSwitchSweep;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\ProfileImageSweep;
use App\Service\Social\ProfileVisibilityCache;
use App\Service\Social\SocialMaintenance;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\StubSocialCandidates;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class MaintenanceTickTest extends DbTestCase
{
    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run();

        // The shared test database may hold other classes' rows: this proves the sweeps ran, not their counts.
        self::assertFalse($report->refresh->isAborted());
        self::assertFalse($report->sweeps->skipped);
    }

    /**
     * An aborted refresh closed the shared EntityManager, so the sweeps must not run; the throwing
     * DigestRecipientsInterface stub fails this test if SendDueDigests::run() is ever reached on that path.
     */
    public function testSkipsTheRecommendationSweepWhenRefreshAborts(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $subscriber = new User('tick-abort-fixture@example.com', $clock->now());
        $this->entityManager->persist($subscriber);

        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($clock->now()->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $clock->now()));
        $this->entityManager->flush();

        $passes = new StubPassFetcher($clock);
        $passes->willReturn(
            $feed->getUrl(),
            FetchResponseModel::fetched(
                $feed->getUrl(),
                false,
                /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title>'
                    . '<item><title>Post</title><link>https://one.example.com/p</link><guid>g-1</guid></item>'
                    . '</channel></rss>',
                null,
                null,
            ),
        );

        $failingEntityManager = new FlushFailingEntityManager(
            $this->entityManager,
            thrown: DuplicateKeyViolation::exception(),
        );

        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->flushingThrough($failingEntityManager)
            ->build($passes, new StubFeedFetcher());

        $throwingPreferences = $this->createStub(DigestRecipientsInterface::class);
        $throwingPreferences->method('findWithDigestEnabled')->willThrowException(
            new \LogicException('SendDueDigests::run() must not be called when refresh aborted'),
        );

        $digestSchedule = self::getContainer()->get(DigestSchedule::class);
        self::assertInstanceOf(DigestSchedule::class, $digestSchedule);
        $digestComposer = self::getContainer()->get(DigestComposer::class);
        self::assertInstanceOf(DigestComposer::class, $digestComposer);
        $digestMailer = self::getContainer()->get(DigestMailerInterface::class);
        self::assertInstanceOf(DigestMailerInterface::class, $digestMailer);
        $mailCapability = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mailCapability);

        $sendDueDigests = new SendDueDigests(
            $throwingPreferences,
            $digestSchedule,
            $digestComposer,
            $digestMailer,
            $mailCapability,
            $clock,
            $this->entityManager,
            new NullLogger(),
            new InMemoryMailFailureRecorder(),
        );

        $linkCardSweep = self::getContainer()->get(LinkCardSweep::class);
        self::assertInstanceOf(LinkCardSweep::class, $linkCardSweep);

        $tick = $this->tickAround($refreshRunner, $sendDueDigests, $linkCardSweep, $clock);

        $report = $tick->run();

        self::assertTrue($report->refresh->isAborted());
        self::assertEquals(MaintenanceSweepsModel::skippedAfterAbortedRefresh(), $report->sweeps);
        self::assertEquals(new LokiSpoolReportModel(0, 0), $report->logShipping);
    }

    public function testCardsTheQueuedLinkPostsInTheSpareWindowOfATickWhoseRefreshTakesNoTime(): void
    {
        $this->assertLinkCardSweepOfATickWhoseRefreshTakes(0, new LinkCardSweepReportModel(1, 1), 1);
    }

    public function testLeavesTheLinkPostsQueuedWhenTheRefreshSpentTheWindow(): void
    {
        $this->assertLinkCardSweepOfATickWhoseRefreshTakes(18, new LinkCardSweepReportModel(0, 0), 0);
    }

    public function testAsksForProfilePicturesInTheSpareWindowOfATickWhoseRefreshTakesNoTime(): void
    {
        self::assertSame(1, $this->profilePicturesCheckedByATickWhoseRefreshTakes(0));
    }

    public function testLeavesProfilePicturesForLaterWhenTheRefreshSpentTheWindow(): void
    {
        self::assertSame(0, $this->profilePicturesCheckedByATickWhoseRefreshTakes(18));
    }

    private function profilePicturesCheckedByATickWhoseRefreshTakes(int $refreshSeconds): int
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-profile-pictures@example.com', $now);
        $this->entityManager->persist($subscriber);
        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($now->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $now));
        $profileFeed = new Feed('https://bsky.app/profile/tick.bsky.social/rss');
        $profileFeed->scheduleNextFetchAt($now->modify('+1 hour'));
        $this->entityManager->persist($profileFeed);
        $this->entityManager->persist(new Subscription($subscriber, $profileFeed, $now));
        $this->entityManager->flush();
        $passes = new StubPassFetcher($clock);
        $passes->secondsPerFetch = $refreshSeconds;
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $appView = new StubFeedFetcher();
        $appView->willReturnBody(
            'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfiles?actors=tick.bsky.social',
            '{"profiles":[]}',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, $appView);
        $sendDueDigests = self::getContainer()->get(SendDueDigests::class);
        self::assertInstanceOf(SendDueDigests::class, $sendDueDigests);
        $linkCardSweep = LinkCardSweepWiring::build($this->entityManager, new MockHttpClient(), $clock);

        $report = $this->tickAround($refreshRunner, $sendDueDigests, $linkCardSweep, $clock, $appView)->run();

        return $report->sweeps->profileImages->checked;
    }

    public function testMovesHiddenProfilesInTheSpareWindowOfATickWhoseRefreshTakesNoTime(): void
    {
        self::assertSame(1, $this->hiddenProfilesStartedByATickWhoseRefreshTakes(0));
    }

    public function testLeavesHiddenProfilesForLaterWhenTheRefreshSpentTheWindow(): void
    {
        self::assertSame(0, $this->hiddenProfilesStartedByATickWhoseRefreshTakes(18));
    }

    private function hiddenProfilesStartedByATickWhoseRefreshTakes(int $refreshSeconds): int
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-hidden-profiles@example.com', $now);
        $this->entityManager->persist($subscriber);
        $this->entityManager->flush();
        $this->entityManager->persist(SocialAccounts::blueskyOf($subscriber));
        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($now->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $now));
        $refused = new Feed('https://bsky.app/profile/did:plc:rusty/rss');
        $refused->recordFailedFetch($now, 'https://bsky.app/profile/did:plc:rusty/rss: HTTP 403', 60);
        $refused->scheduleNextFetchAt($now->modify('+1 hour'));
        $this->entityManager->persist($refused);
        $this->entityManager->persist(new Subscription($subscriber, $refused, $now));
        $this->entityManager->flush();
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);
        $hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $passes = new StubPassFetcher($clock);
        $passes->secondsPerFetch = $refreshSeconds;
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, new StubFeedFetcher());
        $sendDueDigests = self::getContainer()->get(SendDueDigests::class);
        self::assertInstanceOf(SendDueDigests::class, $sendDueDigests);
        $linkCardSweep = LinkCardSweepWiring::build($this->entityManager, new MockHttpClient(), $clock);

        $report = $this->tickAround($refreshRunner, $sendDueDigests, $linkCardSweep, $clock)->run();

        return $report->sweeps->hiddenProfiles->started;
    }

    private function assertLinkCardSweepOfATickWhoseRefreshTakes(
        int $refreshSeconds,
        LinkCardSweepReportModel $expectedSweep,
        int $expectedPageFetches,
    ): void {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->queueLinkPost($clock);
        $pages = ShareLinkCardsWiring::titledPages();
        $linkCardSweep = LinkCardSweepWiring::build($this->entityManager, $pages, $clock);
        $passes = new StubPassFetcher($clock);
        $passes->secondsPerFetch = $refreshSeconds;
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, new StubFeedFetcher());
        $sendDueDigests = self::getContainer()->get(SendDueDigests::class);
        self::assertInstanceOf(SendDueDigests::class, $sendDueDigests);

        $report = $this->tickAround($refreshRunner, $sendDueDigests, $linkCardSweep, $clock)->run();

        self::assertFalse($report->refresh->isAborted());
        self::assertEquals($expectedSweep, $report->sweeps->linkCards);
        self::assertSame($expectedPageFetches, $pages->getRequestsCount());
    }

    private function queueLinkPost(MockClock $clock): void
    {
        $now = $clock->now();
        $subscriber = new User('tick-link-cards-fixture@example.com', $now);
        $this->entityManager->persist($subscriber);
        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($now->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $now));
        QueuedLinkPosts::persist(
            $this->entityManager,
            $feed,
            'link-post',
            '<p>https://example.com/1</p>',
            $now->modify('-1 minute'),
        );
    }

    private function tickAround(
        RefreshRunner $refreshRunner,
        SendDueDigests $sendDueDigests,
        LinkCardSweep $linkCardSweep,
        MockClock $clock,
        ?StubFeedFetcher $appViewFetcher = null,
    ): MaintenanceTick {
        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);
        $pendingImageVerificationRepository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertInstanceOf(PendingImageVerificationRepository::class, $pendingImageVerificationRepository);

        return new MaintenanceTick(
            $refreshRunner,
            $forYouSweep,
            $sendDueDigests,
            new ImageVerificationSweep(
                $pendingImageVerificationRepository,
                new ImageVerifier(new StubGuardedImageDownloader(), new NaiveUtcClock($clock)),
                $this->entityManager,
            ),
            $linkCardSweep,
            new SocialMaintenance(
                $this->profileImageSweep($appViewFetcher ?? self::unreachableAppView(), $clock),
                $this->hiddenProfileSwitchSweep(),
                PrivateFeedSyncWiring::sweep(
                    self::getContainer(),
                    $this->entityManager,
                    $clock,
                    new StubSocialCandidates(new SocialNetworkUnreachableException('no network in this test')),
                ),
                $clock,
            ),
            MembershipSweepFactory::fromContainer(
                self::getContainer(),
                $this->entityManager,
                new RecordingSavedSearchMatcher(),
                $clock,
            ),
            new LokiSpoolShipper(
                new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
                sys_get_temp_dir() . '/loki-tick-' . bin2hex(random_bytes(4)),
            ),
            $clock,
        );
    }

    private function hiddenProfileSwitchSweep(): HiddenProfileSwitchSweep
    {
        $switches = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $switches);

        return $switches;
    }

    private function profileImageSweep(StubFeedFetcher $fetcher, MockClock $clock): ProfileImageSweep
    {
        $feeds = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);

        return new ProfileImageSweep(
            $feeds,
            new AppViewClient($fetcher, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
            $this->entityManager,
            new NaiveUtcClock($clock),
            new NullLogger(),
            new ProfileVisibilityCache(new ArrayAdapter()),
        );
    }

    private static function unreachableAppView(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrowForEverythingElse(new FeedUnreachableException('no AppView in this test'));

        return $fetcher;
    }
}
