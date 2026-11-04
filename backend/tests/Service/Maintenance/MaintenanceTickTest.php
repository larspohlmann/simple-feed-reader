<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Maintenance\MaintenanceSweep\DigestsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\HiddenProfilesTickSweep;
use App\Service\Maintenance\MaintenanceSweep\ImageVerificationTickSweep;
use App\Service\Maintenance\MaintenanceSweep\LinkCardsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\MaintenanceSweepInterface;
use App\Service\Maintenance\MaintenanceSweep\PrivateFeedSyncTickSweep;
use App\Service\Maintenance\MaintenanceSweep\ProfileImagesTickSweep;
use App\Service\Maintenance\MaintenanceSweep\RecommendationsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\SavedSearchMembershipsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\TranscriptsTickSweep;
use App\Service\Maintenance\MaintenanceSweep\TrendingTickSweep;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\ImageVerificationSweepWiring;
use App\Tests\Support\ClockStampingCandidates;
use App\Tests\Support\ClockStampingMatcher;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\LinkCardSweepWiring;
use App\Tests\Support\MaintenanceTickDoubles;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\QueuedLinkPosts;
use App\Tests\Support\RecordingMaintenanceSweep;
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
use App\Service\Social\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\ProfileImageSweep;
use App\Service\Social\ProfileVisibilityCache;
use App\Service\Transcript\TranscriptSweep;
use App\Service\Trending\Model\TrendingRebuildReportModel;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\StageStamps;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\TickingClock;
use App\Tests\Support\TrendingWiring;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class MaintenanceTickTest extends DbTestCase
{
    private const int TICK_WINDOW_SECONDS = 25;
    private const int TRENDING_FIRST_BUILDS_SECONDS = 30;

    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run();

        // The shared test database may hold other classes' rows: this proves the sweeps ran, not their counts.
        self::assertFalse($report->refresh->isAborted());
        self::assertFalse($report->sweeps->skipped);
    }

    /** An aborted refresh closed the shared EntityManager, so the sweeps must not run. */
    public function testSkipsEverySweepWhenRefreshAborts(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $sweep = new RecordingMaintenanceSweep();

        $report = $this->tickOver($this->abortingRefreshRunner($clock), $sweep, $clock)->run();

        self::assertTrue($report->refresh->isAborted());
        self::assertTrue($report->sweeps->skipped);
        self::assertSame([], $sweep->deadlines);
        self::assertEquals(['recording' => RecordingMaintenanceSweep::idle()], $report->sweeps->reports);
        self::assertEquals(new LokiSpoolReportModel(0, 0), $report->logShipping);
    }

    public function testRunsEverySweepUpToTheEndOfTheTickWindowCountedFromItsStart(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $sweep = new RecordingMaintenanceSweep();
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build(new StubPassFetcher(), new StubFeedFetcher());

        $report = $this->tickOver($refreshRunner, $sweep, $clock)->run();

        self::assertFalse($report->sweeps->skipped);
        self::assertEquals([
            new \DateTimeImmutable('2026-08-10 12:00:25', new \DateTimeZone('UTC')),
        ], $sweep->deadlines);
        self::assertEquals(['recording' => RecordingMaintenanceSweep::ran()], $report->sweeps->reports);
    }

    private function abortingRefreshRunner(MockClock $clock): RefreshRunner
    {
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

        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->flushingThrough($failingEntityManager)
            ->build($passes, new StubFeedFetcher());
    }

    private function tickOver(
        RefreshRunner $refreshRunner,
        RecordingMaintenanceSweep $sweep,
        ClockInterface $clock,
    ): MaintenanceTick {
        return new MaintenanceTick(
            $refreshRunner,
            [RecordingMaintenanceSweep::name() => $sweep],
            new LokiSpoolShipper(
                new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
                sys_get_temp_dir() . '/loki-tick-' . bin2hex(random_bytes(4)),
            ),
            $clock,
            self::TICK_WINDOW_SECONDS,
        );
    }

    public function testCardsTheQueuedLinkPostsInTheSpareWindowOfATickWhoseRefreshTakesNoTime(): void
    {
        $this->assertLinkCardSweepOfATickWhoseRefreshTakes(0, new LinkCardSweepReportModel(1, 1), 1);
    }

    public function testLeavesTheLinkPostsQueuedWhenTheRefreshSpentTheWindow(): void
    {
        $this->assertLinkCardSweepOfATickWhoseRefreshTakes(18, LinkCardSweepReportModel::none(), 0);
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

        $report = $this->tickAround(
            new MaintenanceTickDoubles($refreshRunner, $clock, self::TICK_WINDOW_SECONDS, appViewFetcher: $appView),
        )->run();

        return self::reportOf($report, 'profileImages', ProfileImageSweepReportModel::class)->checked;
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

        $report = $this->tickAround(
            new MaintenanceTickDoubles($refreshRunner, $clock, self::TICK_WINDOW_SECONDS),
        )->run();

        return self::reportOf($report, 'hiddenProfiles', HiddenProfileSwitchReportModel::class)->started;
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

        $report = $this->tickAround(
            new MaintenanceTickDoubles(
                $refreshRunner,
                $clock,
                self::TICK_WINDOW_SECONDS,
                linkCardSweep: $linkCardSweep,
            ),
        )->run();

        self::assertFalse($report->refresh->isAborted());
        self::assertEquals($expectedSweep, $report->sweeps->reports['linkCards']);
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

    public function testRebuildsTheDueTrendingWindows(): void
    {
        $clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $now = $clock->now();
        $subscriber = new User('tick-trending@example.com', $now);
        $this->entityManager->persist($subscriber);
        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($now->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $now));
        $this->entityManager->flush();
        $passes = new StubPassFetcher($clock);
        $passes->willReturnBody(
            'https://one.example.com/feed',
            /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title></channel></rss>',
        );
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build($passes, new StubFeedFetcher());

        $report = $this->tickAround(
            new MaintenanceTickDoubles($refreshRunner, $clock, self::TRENDING_FIRST_BUILDS_SECONDS),
        )->run();

        self::assertCount(2, self::reportOf($report, 'trending', TrendingRebuildReportModel::class)->builds);
    }

    public function testSweepsMembershipsThenSyncsPrivateFeedsThenRebuildsTrending(): void
    {
        $clock = new TickingClock(new \DateTimeImmutable('2031-10-11 12:00:00', new \DateTimeZone('UTC')), 1);
        $owner = new User('tick-order@example.com', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $feed = new Feed('https://order.example.com/feed');
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2032-01-01 00:00:00'));
        $owner->approve($clock->now());
        $this->entityManager->persist($owner);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($owner, $feed, $clock->now()));
        $this->entityManager->persist(new SavedSearch($owner, 'climate', false));
        $this->entityManager->flush();
        $this->entityManager->persist(new Entry(
            $feed,
            'order-1',
            'https://order.example.com/1',
            'Title',
            new \DateTimeImmutable('2031-10-11 00:00:00'),
            new \DateTimeImmutable('2031-10-11 00:00:00'),
        ));
        $this->entityManager->persist(SocialAccounts::blueskyOf($owner));
        $this->entityManager->flush();
        $stamps = new StageStamps();
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->build(new StubPassFetcher(), new StubFeedFetcher());

        $this->tickAround(new MaintenanceTickDoubles(
            $refreshRunner,
            $clock,
            100_000,
            matcher: new ClockStampingMatcher($stamps, $clock),
            network: new ClockStampingCandidates($stamps, $clock),
        ))->run();

        $builtAt = $this->entityManager->getConnection()->fetchOne('SELECT MIN(built_at) FROM trending_snapshot');
        self::assertIsString($builtAt);
        self::assertSame(['memberships', 'sync'], $stamps->stages());
        self::assertLessThan($stamps->at('sync'), $stamps->at('memberships'));
        self::assertLessThan(new \DateTimeImmutable($builtAt, new \DateTimeZone('UTC')), $stamps->at('sync'));
    }

    private function tickAround(MaintenanceTickDoubles $doubles): MaintenanceTick
    {
        $clock = $doubles->clock;
        $sendDueDigests = self::getContainer()->get(SendDueDigests::class);
        self::assertInstanceOf(SendDueDigests::class, $sendDueDigests);
        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);
        $transcriptSweep = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $transcriptSweep);
        $imageVerificationSweep = ImageVerificationSweepWiring::build(
            self::getContainer(),
            $this->entityManager,
            new StubGuardedImageDownloader(),
            $clock,
        );

        return new MaintenanceTick(
            $doubles->refreshRunner,
            self::inDeclaredOrder([
                new TrendingTickSweep(TrendingWiring::rebuild($this->entityManager, $clock)),
                new PrivateFeedSyncTickSweep(
                    PrivateFeedSyncWiring::sweep(
                        self::getContainer(),
                        $this->entityManager,
                        $clock,
                        $doubles->network ?? new StubSocialCandidates(
                            new SocialNetworkUnreachableException('no network in this test'),
                        ),
                    ),
                    $clock,
                ),
                new SavedSearchMembershipsTickSweep(
                    MembershipSweepFactory::fromContainer(
                        self::getContainer(),
                        $this->entityManager,
                        $doubles->matcher ?? new RecordingSavedSearchMatcher(),
                        $clock,
                    ),
                    $clock,
                ),
                new HiddenProfilesTickSweep($this->hiddenProfileSwitchSweep(), $clock),
                new ProfileImagesTickSweep(
                    $this->profileImageSweep($doubles->appViewFetcher ?? self::unreachableAppView(), $clock),
                    $clock,
                ),
                new TranscriptsTickSweep($transcriptSweep, $clock),
                new LinkCardsTickSweep(
                    $doubles->linkCardSweep
                        ?? LinkCardSweepWiring::build($this->entityManager, new MockHttpClient(), $clock),
                    $clock,
                ),
                new ImageVerificationTickSweep($imageVerificationSweep, $clock),
                new DigestsTickSweep($sendDueDigests),
                new RecommendationsTickSweep($forYouSweep),
            ]),
            new LokiSpoolShipper(
                new LokiClient(new MockHttpClient(), new StubLokiEndpoint()),
                sys_get_temp_dir() . '/loki-tick-' . bin2hex(random_bytes(4)),
            ),
            $clock,
            $doubles->windowSeconds,
        );
    }

    /**
     * Ordered and keyed the way the container's tagged iterator hands them to the tick, so a swapped priority fails.
     *
     * @param list<MaintenanceSweepInterface> $sweeps
     *
     * @return array<string, MaintenanceSweepInterface>
     */
    private static function inDeclaredOrder(array $sweeps): array
    {
        usort(
            $sweeps,
            static fn (MaintenanceSweepInterface $left, MaintenanceSweepInterface $right): int
                => $right::priority() <=> $left::priority(),
        );
        $keyed = [];
        foreach ($sweeps as $sweep) {
            $keyed[$sweep::name()] = $sweep;
        }

        return $keyed;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function reportOf(MaintenanceTickReportModel $report, string $sweep, string $class): object
    {
        $sweepReport = $report->sweeps->reports[$sweep];
        self::assertInstanceOf($class, $sweepReport);

        return $sweepReport;
    }

    private function hiddenProfileSwitchSweep(): HiddenProfileSwitchSweep
    {
        $switches = self::getContainer()->get(HiddenProfileSwitchSweep::class);
        self::assertInstanceOf(HiddenProfileSwitchSweep::class, $switches);

        return $switches;
    }

    private function profileImageSweep(StubFeedFetcher $fetcher, ClockInterface $clock): ProfileImageSweep
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
