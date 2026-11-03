<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryShareRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryShareFixtures;
use App\Tests\Support\SavedPostFixtures;

final class EntryShareRepositoryTest extends DbTestCase
{
    private const string AT = '2026-10-02T10:00:00Z';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('sharer@example.com', new \DateTimeImmutable(self::AT));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testRecordingSharesASubscribedEntry(): void
    {
        $entry = $this->entryIn($this->subscribedFeed('https://record.example/feed.xml'), 'a');

        $this->shares()->record($this->user->requireId(), $entry->requireId(), new \DateTimeImmutable(self::AT));

        self::assertEquals(new \DateTimeImmutable(self::AT), $this->sharedAtOf($entry));
    }

    public function testRecordingAgainMovesTheShareOn(): void
    {
        $entry = $this->entryIn($this->subscribedFeed('https://reshare.example/feed.xml'), 'a');
        $later = new \DateTimeImmutable('2026-10-09T10:00:00Z');

        $this->shares()->record($this->user->requireId(), $entry->requireId(), new \DateTimeImmutable(self::AT));
        $this->shares()->record($this->user->requireId(), $entry->requireId(), $later);

        self::assertEquals($later, $this->sharedAtOf($entry));
    }

    public function testAnEntryTheUserNoLongerSubscribesToIsLeftUnshared(): void
    {
        $feed = new Feed('https://left.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = $this->entryIn($feed, 'a');

        $this->shares()->record($this->user->requireId(), $entry->requireId(), new \DateTimeImmutable(self::AT));

        self::assertNull($this->sharedAtOf($entry));
    }

    public function testAnOrphanedShareIsNotMovedOnByARecord(): void
    {
        $feed = new Feed('https://orphan-record.example/feed.xml');
        $this->entityManager->persist($feed);
        $entry = $this->entryIn($feed, 'a');
        EntryShareFixtures::share($this->entityManager, $this->user, $entry, new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $this->shares()->record(
            $this->user->requireId(),
            $entry->requireId(),
            new \DateTimeImmutable('2026-10-09T10:00:00Z'),
        );

        self::assertEquals(new \DateTimeImmutable(self::AT), $this->sharedAtOf($entry));
    }

    public function testRemovingDeletesOnlyThatShare(): void
    {
        $feed = $this->subscribedFeed('https://remove.example/feed.xml');
        $removed = $this->entryIn($feed, 'a');
        $kept = $this->entryIn($feed, 'b');
        EntryShareFixtures::share($this->entityManager, $this->user, $removed, new \DateTimeImmutable(self::AT));
        EntryShareFixtures::share($this->entityManager, $this->user, $kept, new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $this->shares()->remove($this->user->requireId(), $removed->requireId());

        self::assertNull($this->sharedAtOf($removed));
        self::assertNotNull($this->sharedAtOf($kept));
    }

    public function testTheCountLeavesOutSharesInFeedsTheUserLeft(): void
    {
        $subscribed = $this->entryIn($this->subscribedFeed('https://counted.example/feed.xml'), 'a');
        $left = new Feed('https://orphaned.example/feed.xml');
        $this->entityManager->persist($left);
        $orphan = $this->entryIn($left, 'b');
        EntryShareFixtures::share($this->entityManager, $this->user, $subscribed, new \DateTimeImmutable(self::AT));
        EntryShareFixtures::share($this->entityManager, $this->user, $orphan, new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        self::assertSame(1, $this->shares()->countForUser($this->user->requireId()));
    }

    public function testDeletingTheEntryDeletesItsShare(): void
    {
        $entry = $this->entryIn($this->subscribedFeed('https://cascade-entry.example/feed.xml'), 'a');
        EntryShareFixtures::share($this->entityManager, $this->user, $entry, new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM entry WHERE id = ?', [$entry->requireId()]);

        self::assertSame(0, $this->shareRows());
    }

    public function testDeletingTheUserDeletesTheirShares(): void
    {
        $entry = $this->entryIn($this->subscribedFeed('https://cascade-user.example/feed.xml'), 'a');
        EntryShareFixtures::share($this->entityManager, $this->user, $entry, new \DateTimeImmutable(self::AT));
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM subscription WHERE user_id = ?', [$this->user->requireId()]);
        $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$this->user->requireId()]);

        self::assertSame(0, $this->shareRows());
    }

    private function shares(): EntryShareRepository
    {
        $shares = $this->entityManager->getRepository(EntryShare::class);
        self::assertInstanceOf(EntryShareRepository::class, $shares);

        return $shares;
    }

    private function sharedAtOf(Entry $entry): ?\DateTimeImmutable
    {
        return $this->shares()->sharedAtFor($this->user->requireId(), $entry->requireId());
    }

    private function shareRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM entry_share');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function subscribedFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable(self::AT)));

        return $feed;
    }

    private function entryIn(Feed $feed, string $guid): Entry
    {
        $entry = SavedPostFixtures::entry($this->entityManager, $feed, $guid, null);
        $this->entityManager->flush();

        return $entry;
    }
}
