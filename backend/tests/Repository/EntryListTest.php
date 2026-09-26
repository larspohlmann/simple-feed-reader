<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Pagination\EntryCursor;
use App\Repository\DateOrderedPage;
use App\Repository\DuplicateCollapseDql;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowHydrator;
use App\Repository\EntryQuery;
use App\Repository\EntryScopePredicates;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SearchTermsPredicateBuilder;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use Doctrine\Persistence\ManagerRegistry;

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
    private function entryAt(string $guid, string $createdAt, string $effectiveDate, ?Feed $feed = null): Entry
    {
        $e = new Entry(
            $feed ?? $this->feed,
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

    /**
     * A repository wired with a small window, so the tag-scoped probe/window
     * branch (#1099) can be forced through a handful of fixture rows instead
     * of DateOrderedPage::DEFAULT_WINDOW_SIZE.
     */
    private function repoWithWindow(int $windowSize): EntryListRepository
    {
        $container = self::getContainer();
        /** @var ManagerRegistry $registry */
        $registry = $container->get(ManagerRegistry::class);
        /** @var EntryListRowHydrator $hydrator */
        $hydrator = $container->get(EntryListRowHydrator::class);
        /** @var SearchTermsPredicateBuilder $termsPredicateBuilder */
        $termsPredicateBuilder = $container->get(SearchTermsPredicateBuilder::class);
        /** @var EntryScopePredicates $scope */
        $scope = $container->get(EntryScopePredicates::class);
        /** @var DuplicateCollapseDql $collapse */
        $collapse = $container->get(DuplicateCollapseDql::class);

        return new EntryListRepository(
            $registry,
            $hydrator,
            $termsPredicateBuilder,
            $scope,
            $collapse,
            new DateOrderedPage($windowSize),
        );
    }

    /**
     * The rows a listForUser() call returned and every query it issued, in
     * order — the probe/window/fallback decision is verified against the query
     * shape, since the rows are identical across every branch (#1099). One call,
     * so the assertions never re-run the page.
     *
     * @return array{rows: list<EntryListRow>, queries: list<string>}
     */
    private function recordedList(EntryListRepository $repo, EntryQuery $query): array
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();
        $rows = $repo->listForUser($query);

        return ['rows' => $rows, 'queries' => $recorder->queries()];
    }

    /**
     * @return array{0: Feed, 1: Tag}
     */
    private function taggedFeedAndSubscription(): array
    {
        $feed = new Feed('https://tagged.example.com/feed.xml');
        $this->em->persist($feed);
        $sub = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->em->persist($tag);
        $sub->addTag($tag);
        $this->em->persist($sub);
        $this->em->flush();

        return [$feed, $tag];
    }

    private function entryIn(Feed $feed, string $guid, string $effectiveDate): Entry
    {
        return $this->entryAt($guid, $effectiveDate, $effectiveDate, $feed);
    }

    /**
     * A feed nobody subscribes to. The window probe scans the whole `entry`
     * table regardless of subscription, so a filler row here still counts
     * toward the K-th-newest-entry cutoff without ever matching a scope.
     */
    private function fillerFeed(): Feed
    {
        $feed = new Feed('https://filler.example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /**
     * The list queries the recorder saw carrying the join-order hint. A result
     * assertion cannot catch the hint — the rows are identical with or without
     * it — so this looks at the wire, like the N+1 guard.
     *
     * @return list<string>
     */
    private function joinPrefixQueries(EntryQuery $query): array
    {
        return array_values(array_filter(
            $this->recordedList($this->repo(), $query)['queries'],
            static fn (string $sql): bool => str_contains($sql, 'JOIN_PREFIX'),
        ));
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<string>
     */
    private function guids(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): string => $row->entry->getGuid(), $rows);
    }

    private function cursorAfter(EntryListRow $row): EntryCursor
    {
        return new EntryCursor(
            $row->entry->getEffectiveDate(),
            $row->entry->requireId(),
        );
    }

    public function testTheChronologicalFanInViewsCarryTheJoinOrderHint(): void
    {
        $userId = $this->user->requireId();

        self::assertCount(1, $this->joinPrefixQueries(new EntryQuery($userId, EntryView::All)));
        self::assertCount(1, $this->joinPrefixQueries(new EntryQuery($userId, EntryView::Unread)));
    }

    public function testOldestFirstReversesTheListIdTieBreakIncluded(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('newest', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');
        $this->entryAt('tied-first', $tied, $tied);
        $this->entryAt('tied-second', $tied, $tied);
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');

        $rows = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), order: ListOrder::OldestFirst),
        );

        self::assertSame(['older', 'tied-first', 'tied-second', 'newest'], $this->guids($rows));
    }

    public function testOldestFirstKeysetPaginatesAcrossATiedEffectiveDate(): void
    {
        $tied = '2026-07-12T00:00:00Z';
        $this->entryAt('later', '2026-07-20T00:00:00Z', '2026-07-20T00:00:00Z');
        $this->entryAt('e1', $tied, $tied);
        $this->entryAt('e2', $tied, $tied);
        $this->entryAt('e3', $tied, $tied);
        $userId = $this->user->requireId();

        $page1 = $this->repo()->listForUser(new EntryQuery($userId, limit: 2, order: ListOrder::OldestFirst));
        self::assertSame(['e1', 'e2'], $this->guids($page1));

        $page2 = $this->repo()->listForUser(new EntryQuery(
            $userId,
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertSame(['e3', 'later'], $this->guids($page2));
    }

    public function testViewedViewOldestFirstListsTheEarliestOpenedFirst(): void
    {
        $early = $this->entryAt('early', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $late = $this->entryAt('late', '2026-07-01T00:00:00Z', '2026-07-20T00:00:00Z');
        $earlyState = new EntryState($this->user, $early);
        $earlyState->markViewed(new \DateTimeImmutable('2026-08-05T09:00:00Z'));
        $lateState = new EntryState($this->user, $late);
        $lateState->markViewed(new \DateTimeImmutable('2026-08-01T09:00:00Z'));
        $this->em->persist($earlyState);
        $this->em->persist($lateState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, order: ListOrder::OldestFirst),
        );

        self::assertSame(['late', 'early'], $this->guids($rows));
    }

    public function testAScopedOrStateDrivenViewDropsTheJoinOrderHint(): void
    {
        $userId = $this->user->requireId();

        self::assertSame([], $this->joinPrefixQueries(
            new EntryQuery($userId, EntryView::All, subscriptionId: $this->sub->getId()),
        ));
        self::assertSame([], $this->joinPrefixQueries(new EntryQuery($userId, EntryView::Favorites)));
    }

    public function testNewestFirstAndCarriesSubscriptionTitle(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-12T00:00:00Z');

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));

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

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertSame(['fresh', 'sunk'], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
    }

    public function testNewerEffectiveDateSortsFirst(): void
    {
        $this->entryAt('older', '2026-07-01T00:00:00Z', '2026-07-10T00:00:00Z');
        $this->entryAt('newer', '2026-07-01T00:00:00Z', '2026-07-15T00:00:00Z');

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));

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

        $page1 = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), limit: 2));
        self::assertSame([$e3->getGuid(), $e2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), cursor: $cursor, limit: 2));

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

        $all = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));
        $byGuid = [];
        foreach ($all as $r) {
            $byGuid[$r->entry->getGuid()] = $r;
        }
        self::assertTrue($byGuid['old']->isHidden);   // under the watermark
        self::assertFalse($byGuid['new']->isHidden);  // above it

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertCount(1, $unread);
        self::assertSame('new', $unread[0]->entry->getGuid());
    }

    public function testExplicitStateBeatsWatermark(): void
    {
        $e = $this->entry('x', '2026-07-05T00:00:00Z');
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        // Explicitly unread despite being under the watermark.
        $state = new EntryState($this->user, $e);
        $state->markUnread();
        $this->em->persist($state);
        $this->em->flush();

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertCount(1, $unread);
        self::assertFalse($unread[0]->isHidden);
    }

    public function testFavoritesAndKeptViews(): void
    {
        $fav = $this->entry('fav', '2026-07-05T00:00:00Z');
        $kept = $this->entry('kept', '2026-07-06T00:00:00Z');
        $this->entry('plain', '2026-07-07T00:00:00Z');

        $s1 = new EntryState($this->user, $fav);
        $s1->markFavorite();
        $s2 = new EntryState($this->user, $kept);
        $s2->markKept();
        $this->em->persist($s1);
        $this->em->persist($s2);
        $this->em->flush();

        $favs = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Favorites));
        self::assertCount(1, $favs);
        self::assertSame('fav', $favs[0]->entry->getGuid());
        self::assertTrue($favs[0]->isFavorite);

        $kepts = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Kept));
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

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Viewed));

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
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, limit: 1),
        );
        self::assertCount(1, $page1);
        self::assertSame('first', $page1[0]->entry->getGuid());

        // The cursor carries the last row's viewedAt — the instant the viewed
        // view keyset compares against, not the entry's effectiveDate.
        $cursor = new EntryCursor(
            $page1[0]->viewedAt ?? throw new \LogicException('A viewed row must carry a viewedAt.'),
            $page1[0]->entry->requireId(),
        );
        $page2 = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), view: EntryView::Viewed, cursor: $cursor, limit: 1),
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

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));
        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
    }

    public function testSubscriptionFilterAndCursorPaginate(): void
    {
        $this->entry('e1', '2026-07-10T00:00:00Z');
        $this->entry('e2', '2026-07-11T00:00:00Z');
        $this->entry('e3', '2026-07-12T00:00:00Z');

        $page1 = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $this->sub->getId(), limit: 2),
        );
        self::assertCount(2, $page1);
        self::assertSame('e3', $page1[0]->entry->getGuid());
        self::assertSame('e2', $page1[1]->entry->getGuid());

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $page2 = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), cursor: $cursor, limit: 2));
        self::assertCount(1, $page2);
        self::assertSame('e1', $page2[0]->entry->getGuid());
    }

    public function testSubscriptionFilterExcludesEntriesFromTheUsersOtherSubscription(): void
    {
        $ownEntry = $this->entry('own', '2026-07-10T00:00:00Z');

        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $otherSub = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($otherSub);
        $otherEntry = new Entry(
            $otherFeed,
            'other',
            'https://other.example.com/1',
            'Other',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-11T00:00:00Z'),
        );
        $this->em->persist($otherEntry);
        $this->em->flush();

        $rows = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $this->sub->getId()),
        );

        self::assertSame([$ownEntry->getId()], array_map(static fn ($row) => $row->entry->getId(), $rows));
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

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));
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

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));
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
        $favoriteState->markFavorite();
        $this->em->persist($favoriteState);
        $this->em->flush();

        $all = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $all));

        $unread = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Unread));
        self::assertSame([$entryA->getId()], array_map(static fn ($row) => $row->entry->getId(), $unread));

        $own = $this->repo()->listForUser(
            new EntryQuery($this->user->requireId(), subscriptionId: $excludedSub->getId()),
        );
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $own));

        $tagged = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));
        self::assertContains($entryB->getId(), array_map(static fn ($row) => $row->entry->getId(), $tagged));

        $favorites = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Favorites));
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
        $strangerState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $strangerState->markFavorite();
        $this->em->persist($strangerState);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));
        self::assertCount(1, $rows);
        self::assertSame('shared', $rows[0]->entry->getGuid());
        self::assertFalse($rows[0]->isHidden, 'must not inherit the stranger\'s read flag');
        self::assertFalse($rows[0]->isFavorite, 'must not inherit the stranger\'s favorite flag');
    }

    public function testASurvivorNeverListsItselfAndAnInScopeHiddenCopyAppearsAsADuplicate(): void
    {
        $survivor = new Entry(
            $this->feed,
            'survivor',
            'https://example.com/survivor',
            'Survivor',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->em->persist($survivor);
        $hiddenCopy = new Entry(
            $this->feed,
            'hidden-copy',
            'https://example.com/hidden-copy',
            'Hidden copy',
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            'shared-url-hash',
        );
        $this->em->persist($hiddenCopy);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId()));

        self::assertCount(1, $rows);
        self::assertSame('survivor', $rows[0]->entry->getGuid());
        self::assertSame(
            ['hidden-copy'],
            array_map(static fn ($duplicate) => $duplicate->entry->getGuid(), $rows[0]->duplicates),
        );
    }

    public function testACopyOutsideTheQueryScopeIsNotListedAsADuplicate(): void
    {
        $otherFeed = new Feed('https://other.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $otherSub = new Subscription($this->user, $otherFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $tag = new Tag($this->user, 'news');
        $this->em->persist($tag);
        $otherSub->addTag($tag);
        $this->em->persist($otherSub);

        $outOfScopeCopy = new Entry(
            $this->feed,
            'out-of-scope-copy',
            'https://example.com/out-of-scope-copy',
            'Out of scope copy',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->em->persist($outOfScopeCopy);
        $taggedEntry = new Entry(
            $otherFeed,
            'tagged',
            'https://other.example.com/tagged',
            'Tagged',
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            new \DateTimeImmutable('2026-07-06T00:00:00Z'),
            'shared-url-hash',
        );
        $this->em->persist($taggedEntry);
        $this->em->flush();

        $rows = $this->repo()->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId()));

        self::assertCount(1, $rows);
        self::assertSame('tagged', $rows[0]->entry->getGuid());
        self::assertSame([], $rows[0]->duplicates);
    }

    public function testAttachDuplicatesCarriesTheDuplicateLookupHint(): void
    {
        $survivor = new Entry(
            $this->feed,
            'survivor',
            'https://example.com/survivor',
            'Survivor',
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            new \DateTimeImmutable('2026-07-05T00:00:00Z'),
            'shared-url-hash',
        );
        $this->em->persist($survivor);
        $this->em->persist($this->favorited($survivor));
        $this->em->flush();

        // The favorites view drops the chronological-fan-in hint entirely
        // (testAScopedOrStateDrivenViewDropsTheJoinOrderHint), so a JOIN_PREFIX
        // here can only come from attachDuplicates's own, unconditional hint.
        self::assertCount(
            1,
            $this->joinPrefixQueries(new EntryQuery($this->user->requireId(), view: EntryView::Favorites)),
        );
    }

    private function favorited(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->markFavorite();

        return $state;
    }

    public function testAllItemsScopeIssuesNoProbe(): void
    {
        $this->entry('a', '2026-07-05T00:00:00Z');

        $queries = $this->recordedList(
            $this->repo(),
            new EntryQuery($this->user->requireId(), EntryView::All),
        )['queries'];

        self::assertCount(1, $queries, 'a dense fan-in view must run the page query alone, no probe');
        self::assertStringContainsString('JOIN_PREFIX', $queries[0]);
    }

    public function testFewerThanWindowSizeRowsBeyondCursorRunsASingleHintedQuery(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');

        $repo = $this->repoWithWindow(5);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 10);

        $recorded = $this->recordedList($repo, $query);
        $queries = $recorded['queries'];
        self::assertCount(2, $queries, 'a null probe must still run exactly one hinted page query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[0], 'the probe itself is never hinted');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);

        self::assertSame([$t1->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testDenseTagWindowedAttemptEqualsThePlainQuerysPage(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');

        $repo = $this->repoWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repo, $query);
        $queries = $recorded['queries'];
        self::assertCount(2, $queries, 'a full windowed page must never fall back');
        self::assertStringContainsString('JOIN_PREFIX', $queries[1]);

        self::assertSame([$t1->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testSparseTagFallsBackToThePlainUnhintedQuery(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'f1', '2026-07-10T00:00:00Z');
        $this->entryIn($filler, 'f2', '2026-07-09T00:00:00Z');
        $this->entryIn($filler, 'f3', '2026-07-08T00:00:00Z');
        $t1 = $this->entryIn($feed, 't1', '2026-07-05T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-04T00:00:00Z');

        $repo = $this->repoWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repo, $query);
        $queries = $recorded['queries'];
        self::assertCount(3, $queries, 'a short windowed page must fall back to a third, plain query');
        self::assertStringNotContainsString('JOIN_PREFIX', $queries[2], 'the fallback is deliberately unhinted');

        self::assertSame([$t1->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testScopeWithFewerRowsThanTheLimitReturnsAShortPageWithoutLossOrDuplication(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'f1', '2026-07-10T00:00:00Z');
        $this->entryIn($filler, 'f2', '2026-07-09T00:00:00Z');
        $this->entryIn($filler, 'f3', '2026-07-08T00:00:00Z');
        $t1 = $this->entryIn($feed, 't1', '2026-07-05T00:00:00Z');

        $repo = $this->repoWithWindow(2);
        $rows = $repo->listForUser(new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 5));

        self::assertSame([$t1->getGuid()], array_map(static fn ($row) => $row->entry->getGuid(), $rows));
    }

    public function testATiedWindowBoundaryIsIncludedWholeAndNeverTriggersAnUnnecessaryFallback(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        // t2 and t3 share an effectiveDate and straddle the window pivot; t3 is
        // created second so it sorts first on the id DESC tiebreak.
        $t2 = $this->entryIn($feed, 't2', '2026-07-05T00:00:00Z');
        $t3 = $this->entryIn($feed, 't3', '2026-07-05T00:00:00Z');

        $repo = $this->repoWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), tagId: $tag->getId(), limit: 3);

        $recorded = $this->recordedList($repo, $query);
        self::assertCount(
            2,
            $recorded['queries'],
            'an inclusive >= window bound must keep the tie in one windowed attempt',
        );

        self::assertSame([$t1->getGuid(), $t3->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testCursorContinuesAWindowedPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-14T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-13T00:00:00Z');
        $t3 = $this->entryIn($feed, 't3', '2026-07-12T00:00:00Z');
        $t4 = $this->entryIn($feed, 't4', '2026-07-11T00:00:00Z');
        $this->entryIn($feed, 't5', '2026-07-10T00:00:00Z');

        $repo = $this->repoWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repo->listForUser(new EntryQuery($userId, tagId: $tag->getId(), limit: 2));
        self::assertSame([$t1->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $query = new EntryQuery($userId, tagId: $tag->getId(), cursor: $cursor, limit: 2);

        // A probe without the cursor predicate still falls back to a correct
        // page (just via a 3rd query), so only the query count catches it.
        $recorded = $this->recordedList($repo, $query);
        self::assertCount(2, $recorded['queries'], 'the cursor must keep this page windowed, not fall back');
        self::assertSame([$t3->getGuid(), $t4->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testOldestFirstDenseTagWindowedAttemptEqualsThePlainQuerysPage(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $this->entryIn($this->fillerFeed(), 'filler', '2026-07-08T00:00:00Z');
        $this->entryIn($feed, 't1', '2026-07-09T00:00:00Z');
        $this->entryIn($feed, 't2', '2026-07-10T00:00:00Z');
        $this->entryIn($feed, 't3', '2026-07-11T00:00:00Z');

        $query = new EntryQuery(
            $this->user->requireId(),
            tagId: $tag->getId(),
            limit: 2,
            order: ListOrder::OldestFirst,
        );
        $recorded = $this->recordedList($this->repoWithWindow(2), $query);

        self::assertCount(2, $recorded['queries'], 'a full windowed page must never fall back');
        self::assertSame(['t1', 't2'], $this->guids($recorded['rows']));
    }

    public function testOldestFirstCursorContinuesAWindowedPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        foreach (['t1' => 10, 't2' => 11, 't3' => 12, 't4' => 13, 't5' => 14] as $guid => $day) {
            $this->entryIn($feed, $guid, sprintf('2026-07-%02dT00:00:00Z', $day));
        }
        $repo = $this->repoWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repo->listForUser(
            new EntryQuery($userId, tagId: $tag->getId(), limit: 2, order: ListOrder::OldestFirst),
        );
        self::assertSame(['t1', 't2'], $this->guids($page1));

        $recorded = $this->recordedList($repo, new EntryQuery(
            $userId,
            tagId: $tag->getId(),
            cursor: $this->cursorAfter($page1[1]),
            limit: 2,
            order: ListOrder::OldestFirst,
        ));
        self::assertCount(2, $recorded['queries'], 'the cursor must keep this page windowed, not fall back');
        self::assertSame(['t3', 't4'], $this->guids($recorded['rows']));
    }

    public function testCursorContinuesAFallbackPageWithoutGapOrOverlap(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $t3 = $this->entryIn($feed, 't3', '2026-07-08T00:00:00Z');
        $t4 = $this->entryIn($feed, 't4', '2026-07-07T00:00:00Z');
        $filler = $this->fillerFeed();
        $this->entryIn($filler, 'g1', '2026-07-08T18:00:00Z');
        $this->entryIn($filler, 'g2', '2026-07-08T12:00:00Z');

        $repo = $this->repoWithWindow(2);
        $userId = $this->user->requireId();

        $page1 = $repo->listForUser(new EntryQuery($userId, tagId: $tag->getId(), limit: 2));
        self::assertSame([$t1->getGuid(), $t2->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $page1,
        ));

        $cursor = new EntryCursor(
            $page1[1]->entry->getEffectiveDate(),
            $page1[1]->entry->requireId(),
        );
        $query = new EntryQuery($userId, tagId: $tag->getId(), cursor: $cursor, limit: 2);

        $recorded = $this->recordedList($repo, $query);
        self::assertCount(3, $recorded['queries'], 'the second page must independently fall back');
        self::assertSame([$t3->getGuid(), $t4->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $recorded['rows'],
        ));
    }

    public function testUnreadViewOnADenseTagWhoseNewestEntriesAreAllReadFallsBack(): void
    {
        [$feed, $tag] = $this->taggedFeedAndSubscription();
        $t1 = $this->entryIn($feed, 't1', '2026-07-10T00:00:00Z');
        $t2 = $this->entryIn($feed, 't2', '2026-07-09T00:00:00Z');
        $t3 = $this->entryIn($feed, 't3', '2026-07-08T00:00:00Z');
        $t4 = $this->entryIn($feed, 't4', '2026-07-07T00:00:00Z');
        $this->em->persist($this->hidden($t1));
        $this->em->persist($this->hidden($t2));
        $this->em->flush();

        $repo = $this->repoWithWindow(2);
        $query = new EntryQuery($this->user->requireId(), view: EntryView::Unread, tagId: $tag->getId(), limit: 2);

        $recorded = $this->recordedList($repo, $query);
        self::assertCount(
            3,
            $recorded['queries'],
            'the windowed rows the pivot admits are all read, so it must fall back',
        );

        $rows = $recorded['rows'];
        self::assertSame([$t3->getGuid(), $t4->getGuid()], array_map(
            static fn ($row) => $row->entry->getGuid(),
            $rows,
        ));
        self::assertFalse($rows[0]->isHidden);
        self::assertFalse($rows[1]->isHidden);
    }

    public function testGetRowForUserReturnsTheRowOfASubscribedEntry(): void
    {
        $entry = $this->entry('owned-row', '2026-07-02T00:00:00Z');

        $row = $this->repo()->getRowForUser($this->user->requireId(), $entry->requireId());

        self::assertSame($entry->requireId(), $row->entry->requireId());
    }

    public function testGetRowForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-row');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repo()->getRowForUser($this->user->requireId(), $entry->requireId());
    }

    public function testGetOneSubscribedForUserReturnsASubscribedEntry(): void
    {
        $entry = $this->entry('owned-entry', '2026-07-02T00:00:00Z');

        self::assertSame(
            $entry,
            $this->repo()->getOneSubscribedForUser($this->user->requireId(), $entry->requireId()),
        );
    }

    public function testGetOneSubscribedForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-entry');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repo()->getOneSubscribedForUser($this->user->requireId(), $entry->requireId());
    }

    private function entryOfAnUnsubscribedFeed(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.xml');
        $this->em->persist($feed);

        return $this->entryAt($guid, '2026-07-01T00:00:00Z', '2026-07-01T00:00:00Z', $feed);
    }

    private function hidden(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));

        return $state;
    }
}
