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
        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->otherUser);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testDeleteForUserRemovesOnlyThatUsersItemsAndLeavesTheRunRow(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $item = new RecommendationItem($run, $this->entry('mine'), 1, 'reason');
        $this->entityManager->persist($item);
        $run->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->entityManager->flush();
        $runId = $run->requireId();
        $itemId = $item->getId();
        self::assertNotNull($itemId);

        $otherRun = $this->fixtures->createRun($this->otherUser);
        $otherRun->snapshot([[1]]);
        $otherItem = new RecommendationItem($otherRun, $this->entry('theirs'), 1, 'reason');
        $this->entityManager->persist($otherItem);
        $otherRun->complete(new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->entityManager->flush();
        $otherItemId = $otherItem->getId();
        self::assertNotNull($otherItemId);

        $this->items()->deleteForUser($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting a row is
        // gone or still there, or find() serves the stale in-memory copy.
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(RecommendationItem::class, $itemId));
        self::assertNotNull($this->entityManager->find(RecommendationRun::class, $runId));
        self::assertNotNull($this->entityManager->find(RecommendationItem::class, $otherItemId));
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
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function items(): RecommendationItemRepository
    {
        $items = $this->entityManager->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $items);

        return $items;
    }
}
