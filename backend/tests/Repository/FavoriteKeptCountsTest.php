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

final class FavoriteKeptCountsTest extends DbTestCase
{
    private function repo(): EntryStateRepository
    {
        $repo = $this->em->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $repo);

        return $repo;
    }

    private function entry(Feed $feed, string $g): Entry
    {
        $e = new Entry($feed, $g, null, $g, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($e);

        return $e;
    }

    public function testCountsFavoriteAndKeptForSubscribedFeeds(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('u@example.com', $when);
        $this->em->persist($user);
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, $when));

        $fav = new EntryState($user, $this->entry($feed, 'fav'));
        $fav->setIsFavorite(true);
        $this->em->persist($fav);

        $kept = new EntryState($user, $this->entry($feed, 'kept'));
        $kept->setIsKept(true);
        $this->em->persist($kept);

        $both = new EntryState($user, $this->entry($feed, 'both'));
        $both->setIsFavorite(true);
        $both->setIsKept(true);
        $this->em->persist($both);

        // Read-only state contributes to neither count.
        $read = new EntryState($user, $this->entry($feed, 'read'));
        $read->setIsRead(true);
        $this->em->persist($read);

        $this->em->flush();

        $counts = $this->repo()->favoriteAndKeptCountsForUser((int) $user->getId());
        self::assertSame(2, $counts['favorites']); // fav + both
        self::assertSame(2, $counts['kept']); // kept + both
    }

    public function testIgnoresStatesForFeedsTheUserNoLongerSubscribesTo(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('orphan@example.com', $when);
        $this->em->persist($user);

        // A feed the user does NOT subscribe to: a favourite here is orphaned and
        // does not appear in the Favorites list, so it must not be counted either.
        $feed = new Feed('https://example.com/unsub.xml');
        $this->em->persist($feed);
        $orphan = new EntryState($user, $this->entry($feed, 'orphan'));
        $orphan->setIsFavorite(true);
        $orphan->setIsKept(true);
        $this->em->persist($orphan);
        $this->em->flush();

        $counts = $this->repo()->favoriteAndKeptCountsForUser((int) $user->getId());
        self::assertSame(0, $counts['favorites']);
        self::assertSame(0, $counts['kept']);
    }

    public function testCountsAreScopedToTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $mine = new User('mine@example.com', $when);
        $other = new User('other@example.com', $when);
        $this->em->persist($mine);
        $this->em->persist($other);
        $feed = new Feed('https://example.com/shared.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($mine, $feed, $when));
        $this->em->persist(new Subscription($other, $feed, $when));

        $entry = $this->entry($feed, 'shared');
        $theirs = new EntryState($other, $entry);
        $theirs->setIsFavorite(true);
        $this->em->persist($theirs);
        $this->em->flush();

        $counts = $this->repo()->favoriteAndKeptCountsForUser((int) $mine->getId());
        self::assertSame(0, $counts['favorites']);
        self::assertSame(0, $counts['kept']);
    }
}
