<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Feed;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Component\Clock\MockClock;

/**
 * The safety net behind the immediate reclaim: a pruning refresh must sweep
 * away every feed nobody subscribes to, and a user-triggered refresh must
 * leave them alone so it stays fast.
 */
final class RefreshRunnerOrphanSweepTest extends DbTestCase
{
    private MockClock $clock;
    private StubFeedFetcher $fetcher;
    private StubFeedFetcher $faviconFetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher($this->clock);
        $this->faviconFetcher = new StubFeedFetcher();
    }

    private function runner(): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->em, $this->clock)
            ->build($this->fetcher, $this->faviconFetcher);
    }

    public function testAPruningRefreshDeletesAnOrphanedFeed(): void
    {
        $orphan = new Feed('https://orphan.example.com/rss');
        $this->em->persist($orphan);
        $this->em->flush();
        $orphanId = $orphan->requireId();

        $this->runner()->run(RefreshRequestModel::allDue(budgetSeconds: 30));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($orphanId));
    }

    public function testAUserRefreshLeavesAnOrphanedFeedAlone(): void
    {
        $orphan = new Feed('https://orphan-2.example.com/rss');
        $this->em->persist($orphan);
        $this->em->flush();
        $orphanId = $orphan->requireId();

        $this->runner()->run(RefreshRequestModel::forUser(userId: 1, budgetSeconds: 30));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($orphanId));
    }
}
