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
use App\Repository\EntryListRepository;
use App\Repository\EntryQuery;
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
        $publishedAt = new \DateTimeImmutable($published);
        $e = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
        );
        $e->setPublishedAt($publishedAt);
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    /**
     * Seeds an entry with an explicit list-sort instant, independent of
     * fetch time — the shape the effective-date sort and its keyset actually
     * key on.
     */
    private function entryAt(string $guid, string $createdAt, string $effectiveDate): Entry
    {
        $e = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

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
        self::assertFalse($rows[0]->isHidden);
    }

    public function testSortsByEffectiveDateNotByFetchInstant(): void
    {
        // Both fetched in the same run; the older article sank to its publication date.
        $fetchedAt = '2026-08-14T12:00:00Z';
        $this->entryAt('sunk', $fetchedAt, '2020-03-01T00:00:00Z');
        $this->entryAt('fresh', $fetchedAt, $fetchedAt);

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));

        self::assertSame(['fresh', 'sunk'], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
    }

    public function testNewerEffectiveDateSortsFirst(): void
    {
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $this->entryAt('newer', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));

        self::assertSame('newer', $rows[0]->entry->getGuid());
        self::assertSame('older', $rows[1]->entry->getGuid());
    }

    public function testKeysetPaginatesCorrectlyAcrossATiedEffectiveDate(): void
    {
        // A whole refresh run shares one effective date; id DESC is the only
        // tiebreaker, so a page boundary that falls inside the tied group must
        // neither skip nor repeat a row. An older row on a distinct date sits
        // right after the tied group, so the same query also exercises the
        // `effectiveDate <` disjunct of applyCursor()'s two-part predicate —
        // the strict-less branch never fires on its own inside a tie.
        $tied = '2026-07-01T00:00:00Z';
        $e1 = $this->entryAt('e1', $tied, $tied);
        $e2 = $this->entryAt('e2', $tied, $tied);
        $e3 = $this->entryAt('e3', $tied, $tied);
        $older = $this->entryAt('older', '2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z');

        $page1 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, limit: 2));
        self::assertSame([$e3->getGuid(), $e2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->getId() ?? throw new \LogicException('A persisted entry must have an id.'),
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, cursor: $cursor, limit: 2));

        self::assertSame([$e1->getGuid(), $older->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page2,
        ));
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
        self::assertTrue($byGuid['old']->isHidden);   // under the watermark
        self::assertFalse($byGuid['new']->isHidden);  // above it

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
        $state->setIsHidden(false);
        $this->em->persist($state);
        $this->em->flush();

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertCount(1, $unread);
        self::assertFalse($unread[0]->isHidden);
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

    public function testViewedViewOrdersByViewTimeNotPublishDate(): void
    {
        // 'early' was published FIRST but opened LAST; 'late' the other way round.
        // A publish-date sort would put 'late' on top — the viewed history must
        // put 'early' on top, because that is the more recently opened one.
        $early = $this->entryAt('early', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $late = $this->entryAt('late', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');
        // 'plain' is never opened, so it must not appear in the viewed list.
        $this->entryAt('plain', '2026-07-01T00:00:00Z', '2026-07-30T00:00:00Z');

        $earlyState = new EntryState($this->user, $early);
        $earlyState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $lateState = new EntryState($this->user, $late);
        $lateState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->em->persist($earlyState);
        $this->em->persist($lateState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'viewed'));

        self::assertSame(['early', 'late'], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
        self::assertEquals(new \DateTimeImmutable('2026-08-05T09:00:00Z'), $rows[0]->viewedAt);
    }

    public function testViewedViewKeysetPaginatesByViewTime(): void
    {
        $first = $this->entryAt('first', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $second = $this->entryAt('second', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');

        $firstState = new EntryState($this->user, $first);
        $firstState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $secondState = new EntryState($this->user, $second);
        $secondState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->em->persist($firstState);
        $this->em->persist($secondState);
        $this->em->flush();

        $page1 = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, view: 'viewed', limit: 1),
        );
        self::assertCount(1, $page1);
        self::assertSame('first', $page1[0]->entry->getGuid());

        // The cursor carries the last row's viewedAt — the instant the viewed
        // view keyset compares against, not the entry's effectiveDate.
        $cursor = new EntryCursor(
            $page1[0]->viewedAt ?? throw new \LogicException('A viewed row must carry a viewedAt.'),
            $page1[0]->entry->getId() ?? throw new \LogicException('A persisted entry must have an id.'),
        );
        $page2 = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, view: 'viewed', cursor: $cursor, limit: 1),
        );
        self::assertCount(1, $page2);
        self::assertSame('second', $page2[0]->entry->getGuid());
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
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->getId() ?? throw new \LogicException('A persisted entry must have an id.'),
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, cursor: $cursor, limit: 2));
        self::assertCount(1, $page2);
        self::assertSame('e1', $page2[0]->entry->getGuid());
    }

    public function testExcludesFeedsTheUserDoesNotSubscribeTo(): void
    {
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $this->em->persist($strangerFeed);
        $orphanCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $orphan = new Entry($strangerFeed, 'orphan', null, 'Orphan', $orphanCreatedAt, $orphanCreatedAt);
        $orphan->setPublishedAt(new \DateTimeImmutable('2026-07-20T00:00:00Z'));
        $this->em->persist($orphan);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        foreach ($rows as $r) {
            self::assertNotSame('orphan', $r->entry->getGuid());
        }
    }

    public function testCarriesTheViewedFlag(): void
    {
        $viewed = $this->entry('viewed', '2026-07-05T00:00:00Z');
        $this->entry('untouched', '2026-07-06T00:00:00Z');

        $state = new EntryState($this->user, $viewed);
        $state->markViewed(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        $byGuid = [];
        foreach ($rows as $row) {
            $byGuid[$row->entry->getGuid()] = $row->isViewed;
        }
        self::assertTrue($byGuid['viewed']);
        self::assertFalse($byGuid['untouched']);
    }

    public function testExcludedFeedHiddenFromAllAndUnreadButVisibleInExplicitScopes(): void
    {
        $entryA = $this->entry('a', '2026-07-10T00:00:00Z');

        $excludedFeed = new Feed('https://excluded.example.com/feed.xml');
        $this->em->persist($excludedFeed);
        $excludedSub = new Subscription($this->user, $excludedFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $excludedSub->setIncludeInAllItems(false);
        $tag = new Tag($this->user, 'news');
        $this->em->persist($tag);
        $excludedSub->addTag($tag);
        $this->em->persist($excludedSub);
        $entryB = new Entry(
            $excludedFeed,
            'b',
            'https://excluded.example.com/b',
            'Title b',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-11T00:00:00Z'),
        );
        $this->em->persist($entryB);
        $favoriteState = new EntryState($this->user, $entryB);
        $favoriteState->setIsFavorite(true);
        $this->em->persist($favoriteState);
        $this->em->flush();

        $all = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $all));

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'unread'));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $unread));

        $own = $this->repo()->listForUser(
            new EntryQuery($this->user->getId() ?? 0, subscriptionId: $excludedSub->getId()),
        );
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $own));

        $tagged = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, tagId: $tag->getId()));
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $tagged));

        $favorites = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0, view: 'favorites'));
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $favorites));
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
        $strangerState->setIsHidden(true);
        $strangerState->setIsFavorite(true);
        $this->em->persist($strangerState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->getId() ?? 0));
        self::assertCount(1, $rows);
        self::assertSame('shared', $rows[0]->entry->getGuid());
        self::assertFalse($rows[0]->isHidden, 'must not inherit the stranger\'s read flag');
        self::assertFalse($rows[0]->isFavorite, 'must not inherit the stranger\'s favorite flag');
    }
}
