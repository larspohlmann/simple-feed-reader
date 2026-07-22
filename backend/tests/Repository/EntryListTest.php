<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

final class EntryListTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->sub = new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->sub);

        $this->em->flush();
    }

    private function entry(string $guid, string $published): Entry
    {
        $e = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $e->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    private function repo(): EntryRepository
    {
        $repo = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repo);

        return $repo;
    }

    public function testNewestFirstAndCarriesSubscriptionTitle(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-12T00:00:00Z');

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));

        self::assertCount(2, $rows);
        self::assertSame('Title b', $rows[0]->entry->getTitle());
        self::assertSame($this->sub->getId(), $rows[0]->subscriptionId);
        self::assertSame('Example', $rows[0]->subscriptionTitle);
        self::assertFalse($rows[0]->isRead);
    }

    public function testWatermarkFoldsIntoIsReadAndUnreadFilter(): void
    {
        $this->entry('old', '2026-07-05T00:00:00Z');
        $this->entry('new', '2026-07-20T00:00:00Z');
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->flush();

        $all = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        $byGuid = [];
        foreach ($all as $r) {
            $byGuid[$r->entry->getGuid()] = $r;
        }
        self::assertTrue($byGuid['old']->isRead);   // under the watermark
        self::assertFalse($byGuid['new']->isRead);  // above it

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertCount(1, $unread);
        self::assertSame('new', $unread[0]->entry->getGuid());
    }

    public function testExplicitStateBeatsWatermark(): void
    {
        $e = $this->entry('x', '2026-07-05T00:00:00Z');
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        // Explicitly unread despite being under the watermark.
        $state = new EntryState($this->user, $e);
        $state->setIsRead(false);
        $this->em->persist($state);
        $this->em->flush();

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertCount(1, $unread);
        self::assertFalse($unread[0]->isRead);
    }

    public function testFavoritesAndKeptViews(): void
    {
        $fav = $this->entry('fav', '2026-07-05T00:00:00Z');
        $kept = $this->entry('kept', '2026-07-06T00:00:00Z');
        $this->entry('plain', '2026-07-07T00:00:00Z');

        $s1 = new EntryState($this->user, $fav);
        $s1->setIsFavorite(true);
        $s2 = new EntryState($this->user, $kept);
        $s2->setIsKept(true);
        $this->em->persist($s1);
        $this->em->persist($s2);
        $this->em->flush();

        $favs = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'favorites'));
        self::assertCount(1, $favs);
        self::assertSame('fav', $favs[0]->entry->getGuid());
        self::assertTrue($favs[0]->isFavorite);

        $kepts = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'kept'));
        self::assertCount(1, $kepts);
        self::assertSame('kept', $kepts[0]->entry->getGuid());
    }

    public function testTagFilter(): void
    {
        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $otherSub = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->em->persist($tag);
        $otherSub->addTag($tag);
        $this->em->persist($otherSub);
        $this->em->flush();

        $this->entry('untagged', '2026-07-05T00:00:00Z');
        $tagged = new Entry(
            $otherFeed,
            'tagged',
            'https://other.example.com/1',
            'Tagged',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $tagged->setPublishedAt(new \DateTimeImmutable('2026-07-06T00:00:00Z'));
        $this->em->persist($tagged);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, tagId: $tag->getId()));
        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
    }

    public function testSubscriptionFilterAndCursorPaginate(): void
    {
        $this->entry('e1', '2026-07-10T00:00:00Z');
        $this->entry('e2', '2026-07-11T00:00:00Z');
        $this->entry('e3', '2026-07-12T00:00:00Z');

        $page1 = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, subscriptionId: $this->sub->getId(), limit: 2),
        );
        self::assertCount(2, $page1);
        self::assertSame('e3', $page1[0]->entry->getGuid());
        self::assertSame('e2', $page1[1]->entry->getGuid());

        $cursor = new EntryCursor(
            $page1[1]->entry->getPublishedAt() ?? $page1[1]->entry->getCreatedAt(),
            $page1[1]->entry->getId() ?? 0,
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, cursor: $cursor, limit: 2));
        self::assertCount(1, $page2);
        self::assertSame('e1', $page2[0]->entry->getGuid());
    }

    public function testExcludesFeedsTheUserDoesNotSubscribeTo(): void
    {
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $this->em->persist($strangerFeed);
        $orphan = new Entry($strangerFeed, 'orphan', null, 'Orphan', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $orphan->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        $this->em->persist($orphan);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        foreach ($rows as $r) {
            self::assertNotSame('orphan', $r->entry->getGuid());
        }
    }

    public function testStateIsScopedToTheCaller(): void
    {
        // A second subscriber to the SAME feed/entry. Their read + favorite
        // state must never bleed into our view — the LEFT JOIN is keyed on
        // es.user, so we see only our own (absent) state.
        $entry = $this->entry('shared', '2026-07-05T00:00:00Z');

        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($stranger);
        $this->em->persist(new Subscription($stranger, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $strangerState = new EntryState($stranger, $entry);
        $strangerState->setIsRead(true);
        $strangerState->setIsFavorite(true);
        $this->em->persist($strangerState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        self::assertCount(1, $rows);
        self::assertSame('shared', $rows[0]->entry->getGuid());
        self::assertFalse($rows[0]->isRead, 'must not inherit the stranger\'s read flag');
        self::assertFalse($rows[0]->isFavorite, 'must not inherit the stranger\'s favorite flag');
    }
}
