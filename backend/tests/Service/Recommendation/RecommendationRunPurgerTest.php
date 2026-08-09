<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\RecommendationRunActiveException;
use App\Service\Recommendation\RecommendationRunPurger;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

/**
 * Children delete in an explicit order (logs, then items, then runs) rather
 * than leaning on DB-level cascades, so the order is part of the code and
 * portable across both suite dialects. A second user's rows must survive
 * untouched, and an active run must block the purge outright rather than
 * deleting rows a live tick is still writing to.
 */
final class RecommendationRunPurgerTest extends DbTestCase
{
    private User $user;
    private User $otherUser;
    private Feed $feed;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('purge-owner@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->otherUser = new User('purge-other@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->em->persist($this->otherUser);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function entry(string $guid): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function purger(): RecommendationRunPurger
    {
        $runs = $this->em->getRepository(RecommendationRun::class);
        self::assertInstanceOf(RecommendationRunRepository::class, $runs);
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        self::assertInstanceOf(RecommendationRunLogRepository::class, $logs);
        $items = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $items);

        return new RecommendationRunPurger($runs, $logs, $items);
    }

    public function testPurgeRemovesTheUsersRunsItemsAndLogsButLeavesAnotherUsersAlone(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $item = new RecommendationItem($run, $this->entry('mine'), 1, 'reason');
        $this->em->persist($item);
        $log = $this->fixtures->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $run->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->flush();
        $runId = $run->getId();
        $itemId = $item->getId();
        $logId = $log->getId();
        self::assertNotNull($runId);
        self::assertNotNull($itemId);
        self::assertNotNull($logId);

        $otherRun = $this->fixtures->createRun($this->otherUser);
        $otherRun->snapshot([[1]]);
        $otherItem = new RecommendationItem($otherRun, $this->entry('theirs'), 1, 'reason');
        $this->em->persist($otherItem);
        $otherLog = $this->fixtures->log($otherRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $otherRun->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->flush();
        $otherRunId = $otherRun->getId();
        $otherItemId = $otherItem->getId();
        $otherLogId = $otherLog->getId();
        self::assertNotNull($otherRunId);
        self::assertNotNull($otherItemId);
        self::assertNotNull($otherLogId);

        $this->purger()->purge($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting a row is
        // gone or still there, or find() serves the stale in-memory copy.
        $this->em->clear();
        self::assertNull($this->em->find(RecommendationRun::class, $runId));
        self::assertNull($this->em->find(RecommendationItem::class, $itemId));
        self::assertNull($this->em->find(RecommendationRunLog::class, $logId));
        self::assertNotNull($this->em->find(RecommendationRun::class, $otherRunId));
        self::assertNotNull($this->em->find(RecommendationItem::class, $otherItemId));
        self::assertNotNull($this->em->find(RecommendationRunLog::class, $otherLogId));
    }

    public function testPurgeWithAPendingRunThrowsAndDeletesNothing(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $this->em->flush();
        $runId = $run->getId();
        self::assertNotNull($runId);

        $this->expectException(RecommendationRunActiveException::class);

        try {
            $this->purger()->purge($this->user);
        } finally {
            $this->em->clear();
            self::assertNotNull($this->em->find(RecommendationRun::class, $runId));
        }
    }
}
