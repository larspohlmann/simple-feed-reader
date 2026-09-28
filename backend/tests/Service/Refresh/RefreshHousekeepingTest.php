<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshRequest;
use App\Service\Retention\EntryPruner;
use App\Service\Search\EntryIndexer;
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

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::allDue(30));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($orphanId));
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('Reclaimed orphaned feeds', $this->logger->records[0]['message']);
        self::assertSame(['count' => 1], $this->logger->records[0]['context']);
    }

    public function testAUserRequestLeavesOrphanedFeedsAlone(): void
    {
        $orphanId = $this->orphanedFeed();

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::forUser(1, 30));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($orphanId));
        self::assertSame([], $this->logger->records);
    }

    public function testNothingToReclaimLogsNothing(): void
    {
        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::allDue(30));

        self::assertSame([], $this->logger->records);
    }

    private function housekeeping(): RefreshHousekeeping
    {
        return new RefreshHousekeeping(
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),
            new EntryPruner(
                new RetentionRepository($this->em, new RowIds($this->em)),
                new MockClock('2026-07-21 12:00:00', 'UTC'),
                new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            ),
            $this->logger,
        );
    }

    private function orphanedFeed(): int
    {
        $feed = new Feed('https://orphan.example.com/rss');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed->requireId();
    }
}
