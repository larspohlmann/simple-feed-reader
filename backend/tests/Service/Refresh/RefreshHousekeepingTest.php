<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Retention\EntryPruner;
use App\Service\Retention\RetentionSettings;
use App\Service\Search\EntryIndexer;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\RecordingLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** Pruning itself is pinned by RefreshRunnerTest's two prune tests, against a whole run. */
final class RefreshHousekeepingTest extends DbTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    public function testAPruningRequestReclaimsOrphanedFeedsAndLogsHowMany(): void
    {
        $orphanId = $this->orphanedFeed();

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequestModel::allDue(30));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Feed::class)->find($orphanId));
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('Reclaimed orphaned feeds', $this->logger->records[0]['message']);
        self::assertSame(['count' => 1], $this->logger->records[0]['context']);
    }

    public function testAUserRequestLeavesOrphanedFeedsAlone(): void
    {
        $orphanId = $this->orphanedFeed();

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequestModel::forUser(1, 30));

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(Feed::class)->find($orphanId));
        self::assertSame([], $this->logger->records);
    }

    public function testNothingToReclaimLogsNothing(): void
    {
        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequestModel::allDue(30));

        self::assertSame([], $this->logger->records);
    }

    public function testAPruningRequestRecordsTheDayBeforeTheCapPassPrunesIt(): void
    {
        $subscription = $this->subscriptionOverTheCap();

        $pruned = $this->housekeeping()->pruneEntries(RefreshRequestModel::allDue(30));

        self::assertSame(5, $pruned);
        $subscriptionId = $subscription->requireId();
        self::assertSame(25, $this->storedDays()->since([$subscriptionId], '2026-07-20')->of($subscriptionId, '2026-07-20')->arrived);
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('Recorded subscription history days', $this->logger->records[0]['message']);
        self::assertSame(['count' => 6], $this->logger->records[0]['context']);
    }

    public function testAPruningRequestWithNothingToRecordLogsNothing(): void
    {
        self::assertSame(0, $this->housekeeping()->pruneEntries(RefreshRequestModel::allDue(30)));

        self::assertSame([], $this->logger->records);
    }

    public function testAUserRequestRecordsAndPrunesNothing(): void
    {
        $subscription = $this->subscriptionOverTheCap();

        self::assertSame(0, $this->housekeeping()->pruneEntries(RefreshRequestModel::forUser(1, 30)));

        self::assertSame([], $this->storedDays()->findAll());
        self::assertCount(25, $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $subscription->getFeed()]));
        self::assertSame([], $this->logger->records);
    }

    private function storedDays(): SubscriptionDayRepository
    {
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);

        return $repository;
    }

    private function subscriptionOverTheCap(): Subscription
    {
        $user = new User('cap@example.com', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $feed = new Feed('https://cap.example.com/rss');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-15 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        for ($index = 0; $index < 25; ++$index) {
            $created = new \DateTimeImmutable(sprintf('2026-07-20 10:%02d:00', $index));
            $this->entityManager->persist(new Entry($feed, 'cap-' . $index, null, 'cap-' . $index, $created, $created));
        }
        $this->entityManager->flush();

        return $subscription;
    }

    private function housekeeping(): RefreshHousekeeping
    {
        $settings = self::getContainer()->get(RetentionSettings::class);
        self::assertInstanceOf(RetentionSettings::class, $settings);
        $clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $activity = new SubscriptionActivityRepository($this->entityManager);
        $storedDays = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $storedDays);

        return new RefreshHousekeeping(
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
            new EntryPruner(
                new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
                $settings,
                $clock,
                new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
                20,
            ),
            new SubscriptionHistoryRecorder(
                $activity,
                $storedDays,
                new LiveDayCounter($activity),
                new RetentionHorizon($settings),
                new NaiveUtcClock($clock),
                $this->entityManager,
            ),
            $this->logger,
        );
    }

    private function orphanedFeed(): int
    {
        $feed = new Feed('https://orphan.example.com/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed->requireId();
    }
}
