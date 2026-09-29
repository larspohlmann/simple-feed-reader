<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Tests\DbTestCase;

final class StateCountsTest extends DbTestCase
{
    private function repository(): EntryStateRepository
    {
        $repository = $this->entityManager->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $repository);

        return $repository;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->entityManager->persist($entry);

        return $entry;
    }

    public function testCountsFavoriteKeptAndViewedForSubscribedFeeds(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('u@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));

        $favorite = new EntryState($user, $this->entry($feed, 'fav'));
        $favorite->markFavorite();
        $this->entityManager->persist($favorite);

        $kept = new EntryState($user, $this->entry($feed, 'kept'));
        $kept->markKept();
        $this->entityManager->persist($kept);

        $both = new EntryState($user, $this->entry($feed, 'both'));
        $both->markFavorite();
        $both->markKept();
        $this->entityManager->persist($both);

        // Opened, so it counts as viewed but neither favourite nor kept.
        $viewed = new EntryState($user, $this->entry($feed, 'viewed'));
        $viewed->markViewed($when);
        $this->entityManager->persist($viewed);

        // Read-only state (a mark-all-read sweep, never opened) contributes to
        // no count — least of all "viewed".
        $read = new EntryState($user, $this->entry($feed, 'read'));
        $read->hide($when);
        $this->entityManager->persist($read);

        $this->entityManager->flush();

        $counts = $this->repository()->stateCountsForUser($user->requireId());
        self::assertSame(2, $counts['favorites']); // fav + both
        self::assertSame(2, $counts['kept']); // kept + both
        self::assertSame(1, $counts['viewed']); // viewed only
    }

    public function testMarkingUnreadDropsAnEntryFromTheViewedCount(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('reader@example.com', $when);
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, $when));

        $state = new EntryState($user, $this->entry($feed, 'opened'));
        $state->markViewed($when);
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        self::assertSame(1, $this->repository()->stateCountsForUser($user->requireId())['viewed']);

        // Unread clears "opened", so the viewed count falls back to zero.
        $state->markUnread();
        $this->entityManager->flush();

        self::assertSame(0, $this->repository()->stateCountsForUser($user->requireId())['viewed']);
    }

    public function testIgnoresStatesForFeedsTheUserNoLongerSubscribesTo(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('orphan@example.com', $when);
        $this->entityManager->persist($user);

        // A feed the user does NOT subscribe to: a favourite here is orphaned and
        // does not appear in the Favorites list, so it must not be counted either.
        $feed = new Feed('https://example.com/unsub.xml');
        $this->entityManager->persist($feed);
        $orphan = new EntryState($user, $this->entry($feed, 'orphan'));
        $orphan->markFavorite();
        $orphan->markKept();
        $orphan->markViewed($when);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $counts = $this->repository()->stateCountsForUser($user->requireId());
        self::assertSame(0, $counts['favorites']);
        self::assertSame(0, $counts['kept']);
        self::assertSame(0, $counts['viewed']);
    }

    public function testCountsAreScopedToTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $mine = new User('mine@example.com', $when);
        $other = new User('other@example.com', $when);
        $this->entityManager->persist($mine);
        $this->entityManager->persist($other);
        $feed = new Feed('https://example.com/shared.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($mine, $feed, $when));
        $this->entityManager->persist(new Subscription($other, $feed, $when));

        $entry = $this->entry($feed, 'shared');
        $theirs = new EntryState($other, $entry);
        $theirs->markFavorite();
        $theirs->markViewed($when);
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();

        $counts = $this->repository()->stateCountsForUser($mine->requireId());
        self::assertSame(0, $counts['favorites']);
        self::assertSame(0, $counts['kept']);
        self::assertSame(0, $counts['viewed']);
    }
}
