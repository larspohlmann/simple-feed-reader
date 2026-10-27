<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Retention\EntryPruner;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIndexers;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SubscriptionHistoryFixtures;
use Symfony\Component\Clock\MockClock;

/** Pruning itself, and recording before it, are pinned by RefreshRunnerTest against a whole run. */
final class RefreshHousekeepingTest extends DbTestCase
{
    use SubscriptionHistoryFixtures;

    private const string NOW = '2026-07-21 12:00:00';

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

    public function testAPruningRequestRecordsSubscriptionHistoryAndLogsHowMany(): void
    {
        $subscription = $this->subscriptionWithArrivals();

        $this->housekeeping()->recordSubscriptionHistory(RefreshRequestModel::allDue(30));

        $subscriptionId = $subscription->requireId();
        $stored = $this->storedDays()->since([$subscriptionId], '2026-07-20');
        self::assertSame(3, $stored->of($subscriptionId, '2026-07-20')->arrived);
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('Recorded subscription history days', $this->logger->records[0]['message']);
        self::assertSame(['count' => 6], $this->logger->records[0]['context']);
    }

    public function testAPruningRequestWithNothingToRecordLogsNothing(): void
    {
        $this->housekeeping()->recordSubscriptionHistory(RefreshRequestModel::allDue(30));

        self::assertSame([], $this->logger->records);
    }

    public function testAUserRequestRecordsNothing(): void
    {
        $this->subscriptionWithArrivals();

        $this->housekeeping()->recordSubscriptionHistory(RefreshRequestModel::forUser(1, 30));

        self::assertSame([], $this->storedDays()->findAll());
        self::assertSame([], $this->logger->records);
    }

    private function subscriptionWithArrivals(): Subscription
    {
        $subscription = $this->subscriptionOfNewUser('history@example.com', '2026-07-15 00:00:00');
        for ($index = 0; $index < 3; ++$index) {
            $this->arrival($subscription->getFeed(), 'arrival-' . $index, '2026-07-20 10:00:00');
        }
        $this->entityManager->flush();

        return $subscription;
    }

    private function housekeeping(): RefreshHousekeeping
    {
        return new RefreshHousekeeping(
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
            new EntryPruner(
                new RetentionRepository($this->entityManager, new RowIds($this->entityManager)),
                $this->retentionSettings(),
                new MockClock(self::NOW, 'UTC'),
                EntryIndexers::with(new RecordingSearchIndexWriter()),
            ),
            $this->historyWiring(self::NOW)->recorder(),
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
