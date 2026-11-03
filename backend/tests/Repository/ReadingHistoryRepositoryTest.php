<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ReadingHistoryRepository;
use App\Repository\TitledEntry;
use App\Tests\DbTestCase;
use App\Tests\Support\SavedPostFixtures;

/** The reader's history reads break a tie on the instant by the newest entry (#2007). */
final class ReadingHistoryRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('historian@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->feed = new Feed('https://history.example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
    }

    public function testTwoSharesFromTheSameInstantComeBackHighestIdFirst(): void
    {
        $at = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        $older = SavedPostFixtures::entry($this->entityManager, $this->feed, 'a', 'https://history.example.com/a');
        $newer = SavedPostFixtures::entry($this->entityManager, $this->feed, 'b', 'https://history.example.com/b');
        SavedPostFixtures::state($this->entityManager, $this->user, $older)->markShared($at);
        SavedPostFixtures::state($this->entityManager, $this->user, $newer)->markShared($at);
        $this->entityManager->flush();

        $shared = $this->history()->shared($this->user->requireId(), 10);

        self::assertSame([$newer->requireId(), $older->requireId()], $this->entryIds($shared));
    }

    public function testTwoViewsFromTheSameInstantComeBackHighestIdFirst(): void
    {
        $at = new \DateTimeImmutable('2026-10-02T10:00:00Z');
        $older = SavedPostFixtures::entry($this->entityManager, $this->feed, 'a', 'https://history.example.com/a');
        $newer = SavedPostFixtures::entry($this->entityManager, $this->feed, 'b', 'https://history.example.com/b');
        SavedPostFixtures::state($this->entityManager, $this->user, $older)->markViewed($at);
        SavedPostFixtures::state($this->entityManager, $this->user, $newer)->markViewed($at);
        $this->entityManager->flush();

        $viewed = $this->history()->viewed($this->user->requireId(), 10);

        self::assertSame([$newer->requireId(), $older->requireId()], $this->entryIds($viewed));
    }

    private function history(): ReadingHistoryRepository
    {
        return new ReadingHistoryRepository($this->entityManager);
    }

    /**
     * @param list<TitledEntry> $titled
     * @return list<int>
     */
    private function entryIds(array $titled): array
    {
        return array_map(static fn (TitledEntry $item): int => $item->entry->requireId(), $titled);
    }
}
