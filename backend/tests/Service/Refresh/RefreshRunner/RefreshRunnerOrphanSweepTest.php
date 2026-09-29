<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchResponseModel;
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
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->build($this->fetcher, $this->faviconFetcher);
    }

    public function testAPruningRefreshDeletesAnOrphanedFeed(): void
    {
        $orphan = new Feed('https://orphan.example.com/rss');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->requireId();

        $this->runner()->run(RefreshRequestModel::allDue(budgetSeconds: 30));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Feed::class)->find($orphanId));
    }

    public function testAPruningRefreshSpendsNoRequestOnAnOrphanedFeed(): void
    {
        $orphan = new Feed('https://orphan-3.example.com/rss');
        $subscribed = new Feed('https://subscribed.example.com/rss');
        $subscribed->scheduleNextFetchAt($this->clock->now()->modify('-1 hour'));
        $subscriber = new User('sweep-subscriber@example.com', $this->clock->now());
        $this->entityManager->persist($orphan);
        $this->entityManager->persist($subscribed);
        $this->entityManager->persist($subscriber);
        $this->entityManager->persist(new Subscription($subscriber, $subscribed, $this->clock->now()));
        $this->entityManager->flush();
        $this->fetcher->willThrow($orphan->getUrl(), new FeedUnreachableException('never asked'));
        $this->fetcher->willReturn(
            $subscribed->getUrl(),
            FetchResponseModel::notModified($subscribed->getUrl(), false, null, null),
        );

        $this->runner()->run(RefreshRequestModel::allDue(budgetSeconds: 30));

        self::assertSame([$subscribed->getUrl()], $this->fetcher->fetchedUrls);
    }

    public function testAUserRefreshLeavesAnOrphanedFeedAlone(): void
    {
        $orphan = new Feed('https://orphan-2.example.com/rss');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->requireId();

        $this->runner()->run(RefreshRequestModel::forUser(userId: 1, budgetSeconds: 30));

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(Feed::class)->find($orphanId));
    }
}
