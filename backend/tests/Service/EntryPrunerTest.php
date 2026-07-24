<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\EntryPruner;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class EntryPrunerTest extends DbTestCase
{
    private EntryPruner $pruner;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->pruner = new EntryPruner($this->em, $this->clock);
    }

    private function entry(Feed $feed, string $guid, \DateTimeImmutable $publishedAt): Entry
    {
        $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);

        return $entry;
    }

    public function testPrunesOldEntriesButKeepsProtectedAndRecent(): void
    {
        $feed = new Feed('https://example.com/feed');
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($user);

        $old = $this->clock->now()->modify('-120 days');
        $this->entry($feed, 'old-plain', $old);
        $favorite = $this->entry($feed, 'old-favorite', $old);
        $kept = $this->entry($feed, 'old-kept', $old);
        $oldButRead = $this->entry($feed, 'old-read', $old);
        $this->entry($feed, 'recent', $this->clock->now()->modify('-5 days'));

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->setIsFavorite(true);
        $keptState = new EntryState($user, $kept);
        $keptState->setIsKept(true);
        $readState = new EntryState($user, $oldButRead);
        $readState->setIsRead(true);
        $this->em->persist($favoriteState);
        $this->em->persist($keptState);
        $this->em->persist($readState);
        $this->em->flush();
        $this->em->clear();

        $pruned = $this->pruner->prune();

        self::assertSame(2, $pruned);
        $remainingGuids = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->em->getRepository(Entry::class)->findAll(),
        );
        sort($remainingGuids);
        self::assertSame(['old-favorite', 'old-kept', 'recent'], $remainingGuids);
    }

    public function testProtectionAppliesAcrossUsers(): void
    {
        $feed = new Feed('https://example.com/feed');
        $alice = new User('alice@example.com', $this->clock->now());
        $bob = new User('bob@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($alice);
        $this->em->persist($bob);

        $shared = $this->entry($feed, 'shared', $this->clock->now()->modify('-200 days'));

        $aliceRead = new EntryState($alice, $shared);
        $aliceRead->setIsRead(true);
        $bobKept = new EntryState($bob, $shared);
        $bobKept->setIsKept(true);
        $this->em->persist($aliceRead);
        $this->em->persist($bobKept);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(0, $this->pruner->prune());
        self::assertCount(1, $this->em->getRepository(Entry::class)->findAll());
    }

    public function testDeletingEntryRemovesItsStateRows(): void
    {
        $feed = new Feed('https://example.com/feed');
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($user);

        $doomed = $this->entry($feed, 'doomed', $this->clock->now()->modify('-200 days'));
        $state = new EntryState($user, $doomed);
        $state->setIsRead(true);
        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $this->pruner->prune());
        self::assertCount(0, $this->em->getRepository(EntryState::class)->findAll());
    }

    public function testEntryWithoutPublishedAtUsesCreatedAt(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $undated = new Entry($feed, 'undated', null, 'No date', $this->clock->now()->modify('-200 days'));
        $this->em->persist($undated);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $this->pruner->prune());
    }

    public function testRecentUndatedEntrySurvives(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $fresh = new Entry($feed, 'fresh-undated', null, 'No date', $this->clock->now()->modify('-2 days'));
        $this->em->persist($fresh);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(0, $this->pruner->prune());
    }

    public function testNothingToPruneReturnsZero(): void
    {
        self::assertSame(0, $this->pruner->prune());
    }

    public function testCapsEntriesPerFeedKeepingNewestAndProtected(): void
    {
        // A small cap so the test stays readable; production default is 2000.
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 3);

        $feed = new Feed('https://example.com/feed');
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($user);

        // Five RECENT entries (age pass leaves them all), oldest → newest.
        $e1 = $this->entry($feed, 'e1-oldest', $this->clock->now()->modify('-5 days'));
        $this->entry($feed, 'e2', $this->clock->now()->modify('-4 days'));
        $this->entry($feed, 'e3', $this->clock->now()->modify('-3 days'));
        $this->entry($feed, 'e4', $this->clock->now()->modify('-2 days'));
        $this->entry($feed, 'e5-newest', $this->clock->now()->modify('-1 days'));

        // The oldest is kept, so it survives despite being beyond the cap.
        $keptState = new EntryState($user, $e1);
        $keptState->setIsKept(true);
        $this->em->persist($keptState);
        $this->em->flush();
        $this->em->clear();

        // Non-protected newest-first: e5,e4,e3,e2 → cap 3 keeps e5,e4,e3, drops e2.
        self::assertSame(1, $pruner->prune());
        $remaining = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->em->getRepository(Entry::class)->findAll(),
        );
        sort($remaining);
        self::assertSame(['e1-oldest', 'e3', 'e4', 'e5-newest'], $remaining);
    }

    public function testFeedAtOrUnderCapIsUntouched(): void
    {
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 3);

        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        for ($i = 0; $i < 3; ++$i) {
            $this->entry($feed, 'entry-' . $i, $this->clock->now()->modify(sprintf('-%d days', $i + 1)));
        }
        $this->em->flush();
        $this->em->clear();

        self::assertSame(0, $pruner->prune());
        self::assertCount(3, $this->em->getRepository(Entry::class)->findAll());
    }

    public function testCapIsPerFeedNotGlobal(): void
    {
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 3);

        // Two feeds, each at the cap — globally 4 entries, but per-feed nothing
        // exceeds the cap, so a global cap would wrongly delete here.
        foreach (['https://a.example/feed', 'https://b.example/feed'] as $n => $url) {
            $feed = new Feed($url);
            $this->em->persist($feed);
            $this->entry($feed, "feed{$n}-a", $this->clock->now()->modify('-2 days'));
            $this->entry($feed, "feed{$n}-b", $this->clock->now()->modify('-1 days'));
        }
        $this->em->flush();
        $this->em->clear();

        self::assertSame(0, $pruner->prune());
        self::assertCount(4, $this->em->getRepository(Entry::class)->findAll());
    }
}
