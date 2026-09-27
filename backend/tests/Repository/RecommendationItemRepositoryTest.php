<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

/**
 * recommendation_item.recommendation_run_id cascades on delete, so a test
 * driven through RecommendationRunPurger can never tell deleteForUser()'s own
 * explicit RowIds delete apart from the DB cascade doing the same job. This
 * pins deleteForUser() on its own, called directly, with no run deleted
 * alongside it.
 */
final class RecommendationItemRepositoryTest extends DbTestCase
{
    private User $user;
    private User $otherUser;
    private Feed $feed;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('item-owner@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->otherUser = new User('item-other@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
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

    public function testDeleteForUserRemovesOnlyThatUsersItemsAndLeavesTheRunRow(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $item = new RecommendationItem($run, $this->entry('mine'), 1, 'reason');
        $this->em->persist($item);
        $run->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->flush();
        $runId = $run->requireId();
        $itemId = $item->getId();
        self::assertNotNull($itemId);

        $otherRun = $this->fixtures->createRun($this->otherUser);
        $otherRun->snapshot([[1]]);
        $otherItem = new RecommendationItem($otherRun, $this->entry('theirs'), 1, 'reason');
        $this->em->persist($otherItem);
        $otherRun->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->flush();
        $otherItemId = $otherItem->getId();
        self::assertNotNull($otherItemId);

        $this->items()->deleteForUser($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting a row is
        // gone or still there, or find() serves the stale in-memory copy.
        $this->em->clear();
        self::assertNull($this->em->find(RecommendationItem::class, $itemId));
        self::assertNotNull($this->em->find(RecommendationRun::class, $runId));
        self::assertNotNull($this->em->find(RecommendationItem::class, $otherItemId));
    }

    private function entry(string $guid): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function items(): RecommendationItemRepository
    {
        $items = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $items);

        return $items;
    }
}
